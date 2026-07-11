/**
 * ProLancer Realtime Chat (Pusher)
 * --------------------------------
 * Turns the ProLancer chats from reload-based (email-like) into instant
 * messaging: new messages (and file attachments) appear live for both sides,
 * with no page refresh.
 *
 * Covers:
 *   - Service order chat (Ongoing Service Details) incl. attachments
 *   - Dashboard "Message" chat
 */
(function ($) {
	'use strict';

	var CFG = window.prolancerRealtime || {};
	var PUSHER_ON = parseInt(CFG.enabled, 10) === 1 && CFG.key && typeof Pusher !== 'undefined';
	var pusher = null;

	function getPusher() {
		if (!pusher && PUSHER_ON) {
			pusher = new Pusher(CFG.key, { cluster: CFG.cluster, forceTLS: true });
		}
		return pusher;
	}

	/* ---------------- helpers ---------------- */

	function scrollBottom($box) {
		if ($box && $box.length) {
			$box.scrollTop($box.prop('scrollHeight'));
		}
	}

	/**
	 * A message can now carry several attachments. Render them as thumbnails,
	 * matching how the page renders them server-side on load (pcu_render_attachments),
	 * so an optimistically-appended message looks identical to a reloaded one.
	 *
	 * @param {Array} list [{url, thumb, name, is_image}]
	 */
	function attachmentStrip(list) {
		if (!list || !list.length) {
			return null;
		}

		var $wrap = $('<div/>', { 'class': 'pcu-chat-attachments' });

		list.forEach(function (a) {
			var $a = $('<a/>', {
				'class': 'pcu-chat-thumb',
				href: a.url,
				target: '_blank',
				rel: 'noopener',
				title: a.name
			});

			if (a.is_image && (a.thumb || a.url)) {
				// Explicit size so the chat cannot shift as the image decodes.
				$('<img/>', {
					src: a.thumb || a.url,
					alt: a.name,
					width: 84,
					height: 64
				}).appendTo($a);
			} else {
				var ext = (a.name || '').split('.').pop();
				$('<span/>', { 'class': 'pcu-chat-file' }).text(ext.toUpperCase()).appendTo($a);
			}

			$('<span/>', { 'class': 'pcu-chat-caption' }).text(a.name).appendTo($a);
			$a.appendTo($wrap);
		});

		return $wrap;
	}

	/* ---------------- Dashboard "Message" chat ---------------- */

	function dashAppend($pane, text, mine, avatarHtml) {
		if (!$pane || !$pane.length) {
			return;
		}
		var $box = $pane.find('.chat-box').first();
		if (!$box.length) {
			return;
		}
		var $item = $('<div/>', { 'class': 'chat-list' + (mine ? ' message_sender' : '') });
		var $row = $('<div/>', { 'class': 'row' });
		var $avatar = $('<div/>', { 'class': mine ? 'col-3 text-end' : 'col-3' }).html(avatarHtml || '');
		var $textCol = $('<div/>', { 'class': 'col-9' });
		$('<p/>').text(text).appendTo($textCol);
		if (mine) {
			$row.append($textCol).append($avatar);
		} else {
			$row.append($avatar).append($textCol);
		}
		$item.append($row);
		$box.append($item);
		scrollBottom($box);
	}

	function initDashboardChat() {
		var $sendBtns = $('.send-message');
		if (!$sendBtns.length) {
			return;
		}

		// Replace the plugin's reload-on-send handler.
		$sendBtns.off('click');
		$(document).on('click', '.send-message', function (e) {
			e.preventDefault();
			var $btn = $(this);
			var $form = $btn.closest('form');
			var $ta = $form.find('textarea[name="message"]');
			var msg = $.trim($ta.val());
			if (!msg || $btn.hasClass('sending')) {
				return;
			}
			var receiver = $btn.attr('data-receiver-id');
			var sender = $btn.attr('data-sender-id');
			var nonce = $btn.attr('data-nonce');
			$btn.addClass('sending');

			$.ajax({
				url: CFG.ajaxurl,
				type: 'POST',
				data: {
					action: 'prolancer_ajax_messages',
					receiver_id: receiver,
					sender_id: sender,
					message_data: $form.serialize(),
					nonce: nonce
				},
				success: function (response) {
					$btn.removeClass('sending');
					if (response && response.success === true) {
						dashAppend($btn.closest('.tab-pane'), msg, true, CFG.myUserAvatar);
						$ta.val('');
						if (PUSHER_ON) {
							$.post(CFG.ajaxurl, {
								action: 'prolancer_realtime_push',
								receiver_id: receiver,
								sender_id: sender,
								message: msg,
								nonce: CFG.pushNonce
							});
						}
					} else if (typeof Swal !== 'undefined') {
						Swal.fire({ icon: 'error', title: (response && response.data && response.data.message) || 'Message sending failed.' });
					}
				},
				error: function () {
					$btn.removeClass('sending');
					if (typeof Swal !== 'undefined') {
						Swal.fire({ icon: 'error', title: 'Network error. Please try again.' });
					}
				}
			});
		});

		// Live incoming: subscribe to my own user channel.
		if (PUSHER_ON) {
			var ch = getPusher().subscribe('prolancer-user-' + CFG.userId);
			ch.bind('new-message', function (data) {
				if (!data || data.scope !== 'user') {
					return;
				}
				var $btn = $('.send-message[data-receiver-id="' + data.sender_id + '"]').first();
				if ($btn.length) {
					dashAppend($btn.closest('.tab-pane'), data.message, false, data.avatar);
				} else {
					location.reload();
				}
			});
		}
	}

	/* ---------------- Service order chat ---------------- */

	// Ensure a .chat-box exists (thread may start empty with "No Message found!").
	function ensureServiceChatBox() {
		var $box = $('.chat-box').first();
		if ($box.length) {
			return $box;
		}
		var $form = $('#send-service-message-form');
		if (!$form.length) {
			return $();
		}
		// Remove the "No Message found!" placeholder immediately before the form.
		$form.prevAll('.white-padding').first().remove();
		$box = $('<div/>', { 'class': 'chat-box white-padding mt-4 mb-4' });
		$box.insertBefore($form);
		return $box;
	}

	function serviceAppend(text, mine, avatarHtml, attachments) {
		var $box = ensureServiceChatBox();
		if (!$box.length) {
			return;
		}
		var $item = $('<div/>', { 'class': 'chat-list ' + (mine ? 'message_sender' : 'message_receiver') });
		var $row = $('<div/>', { 'class': 'row' });
		var $textCol = $('<div/>', { 'class': 'col-9' });
		$('<p/>').text(text).appendTo($textCol);

		// Accepts the new list. A bare URL string is still handled, so an older
		// payload arriving mid-deploy does not break the chat.
		var strip = attachmentStrip(
			typeof attachments === 'string'
				? (attachments ? [{ url: attachments, name: attachments.split('/').pop(), is_image: false }] : [])
				: attachments
		);
		if (strip) {
			$textCol.append(strip);
		}
		var $avatar = $('<div/>', { 'class': mine ? 'col-3' : 'col-3' }).html(avatarHtml || '');
		if (mine) {
			$avatar.addClass('text-end');
			$row.append($textCol).append($avatar);
		} else {
			$row.append($avatar).append($textCol);
		}
		$item.append($row);
		$box.append($item);
		scrollBottom($box);
	}

	function initServiceChat() {
		var $btn0 = $('.send-service-message[data-order-id]');
		if (!$btn0.length) {
			return;
		}
		var orderId = $btn0.attr('data-order-id');
		var mySenderId = $btn0.attr('data-sender-id');

		// Replace the plugin's reload-on-send handler.
		$('.send-service-message').off('click');
		$(document).on('click', '.send-service-message', function (e) {
			e.preventDefault();
			var $btn = $(this);
			var $form = $('#send-service-message-form');
			var $ta = $form.find('textarea[name="message"]');
			var msg = $.trim($ta.val());
			var attachmentId = $form.find('.attachment-id').val() || 0;
			// Files alone are a valid message — the Upload button sends them
			// with no text typed. Only bail when there is neither.
			if ((!msg && !attachmentId) || $btn.hasClass('sending')) {
				return;
			}
			var sender = $btn.attr('data-sender-id');
			var receiver = $btn.attr('data-receiver-id');
			var order = $btn.attr('data-order-id');
			var nonce = $btn.attr('data-nonce');
			$btn.addClass('sending');
			$form.addClass('processing-loader');

			$.ajax({
				url: CFG.ajaxurl,
				type: 'POST',
				data: {
					action: 'prolancer_ajax_send_service_message',
					nonce: nonce,
					sender_id: sender,
					receiver_id: receiver,
					order_id: order,
					message_data: $form.serialize()
				},
				success: function (response) {
					if (response && response.success === true) {
						// Relay live + get accurate avatar/attachment URL back.
						$.post(CFG.ajaxurl, {
							action: 'prolancer_realtime_push',
							sender_id: sender,
							receiver_id: receiver,
							order_id: order,
							attachment_id: attachmentId,
							message: msg,
							nonce: CFG.pushNonce
						}, function (pushResp) {
							var avatar = (pushResp && pushResp.data && pushResp.data.avatar) || '';
							var files = (pushResp && pushResp.data && pushResp.data.attachments) || [];
							serviceAppend(msg, true, avatar, files);
							$ta.val('');
							$form.find('.attachment-id').val('');
							$form.find('#upload-message-attachments').val('');
							$btn.removeClass('sending');
							$form.removeClass('processing-loader');

							// Tell the uploader its staged files have been sent, so
							// it clears the strip and doesn't attach them again to
							// the next message.
							document.dispatchEvent(new CustomEvent('pcu:sent'));
						}).fail(function () {
							// Push failed but message is stored; fall back to reload.
							location.reload();
						});
					} else {
						$btn.removeClass('sending');
						$form.removeClass('processing-loader');
						if (typeof Swal !== 'undefined') {
							Swal.fire({ icon: 'error', title: (response && response.data && response.data.message) || 'Message sending failed.' });
						}
					}
				},
				error: function () {
					$btn.removeClass('sending');
					$form.removeClass('processing-loader');
					if (typeof Swal !== 'undefined') {
						Swal.fire({ icon: 'error', title: 'Network error. Please try again.' });
					}
				}
			});
		});

		// Live incoming: subscribe to this order's channel.
		if (PUSHER_ON) {
			var ch = getPusher().subscribe('prolancer-order-' + orderId);
			ch.bind('new-message', function (data) {
				if (!data || data.scope !== 'order') {
					return;
				}
				// Ignore my own echo (already appended optimistically).
				if (String(data.sender_id) === String(mySenderId)) {
					return;
				}
				serviceAppend(data.message, false, data.avatar,
					data.attachments || data.attachment_url);
			});
		}
	}

	/* ---------------- boot ---------------- */

	$(function () {
		// Scroll existing threads to newest.
		$('.chat-box').each(function () {
			scrollBottom($(this));
		});
		initDashboardChat();
		initServiceChat();
	});
})(jQuery);
