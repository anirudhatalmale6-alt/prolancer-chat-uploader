<div class="white-padding mb-4">
  <h2 class="mb-0"><?php echo esc_html__('Message', 'prolancer'); ?></h2>
</div>

<div class="white-padding">
  <?php
  // Initialize variables
  $paged = 1;
  $sender_ids = [];
  $chat_members = [];

  if (get_query_var('paged')) {
    $paged = (int)get_query_var('paged');
  } elseif (get_query_var('page')) {
    $paged = (int)get_query_var('page');
  }

  global $wpdb;
  $table = 'prolancer_messages';

  if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) == $table) {
    $receiver_ids = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT * FROM {$table} WHERE `receiver_id` = %d",
        get_current_user_id()
      )
    );

    if ($receiver_ids) {
      foreach ($receiver_ids as $receiver_id) {  
        $sender_ids[] = (int)$receiver_id->sender_id;
      }

      $chat_members = array_unique($sender_ids); 
      ?>

      <div class="row message-box">
        <div class="col-md-4">
          <div class="message-box-sidebar">
            <div class="nav nav-tabs">
              <?php foreach ($chat_members as $key => $chat_member) : 
                $active_class = ($key === 0) ? 'active' : '';
                $aria_selected = ($key === 0) ? 'true' : 'false';
                $sender_posts = get_posts([
                  'post_type' => 'sellers',
                  'author' => $chat_member,
                  'numberposts' => 1
                ]);
                $sender_profile_image_id = !empty($sender_posts) ? $sender_posts[0]->ID : 0;
                $seller_profile_attachment = $sender_profile_image_id ? get_post_meta($sender_profile_image_id, 'seller_profile_attachment', true) : '';
                $sender_image = $seller_profile_attachment ? wp_get_attachment_image(prolancer_get_image_id($seller_profile_attachment), ['30', '30'], false) : '';
                $user_name = $sender_profile_image_id ? get_post_meta($sender_profile_image_id, 'seller_profile_name', true) : '';
                ?>
                <a href="#" class="nav-link <?php echo esc_attr($active_class); ?>" data-bs-toggle="pill" data-bs-target="#chat-<?php echo esc_attr($key); ?>-tab" type="button" role="tab" aria-selected="<?php echo esc_attr($aria_selected); ?>">
                  <span class="d-flex align-items-center">
                    <?php if (!empty($sender_image)) : 
                      echo wp_kses($sender_image, [
                        'img' => [
                          'src' => [],
                          'alt' => [],
                          'style' => [],
                          'class' => [],
                          'width' => [],
                          'height' => []
                        ]
                      ]);
                    else : 
                      echo get_avatar($chat_member, 30);
                    endif; ?>
                    <span>
                      <?php echo esc_html($user_name ?: get_the_author_meta('display_name', $chat_member)); ?>
                    </span>
                  </span>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <div class="col-md-8">
          <div class="tab-content">
            <?php foreach ($chat_members as $key => $chat_member) : 
              $active_class = ($key === 0) ? 'show active' : '';
              ?>
              <div class="tab-pane fade <?php echo esc_attr($active_class); ?>" id="chat-<?php echo esc_attr($key); ?>-tab">
                <div class="chat-box">
                  <?php
                  $messages = $wpdb->get_results(
                    $wpdb->prepare(
                      "SELECT * FROM {$table} 
                      WHERE (`receiver_id` = %d AND `sender_id` = %d) 
                      OR (`sender_id` = %d AND `receiver_id` = %d) 
                      ORDER BY timestamp",
                      get_current_user_id(),
                      $chat_member,
                      get_current_user_id(),
                      $chat_member
                    )
                  );

                  foreach ($messages as $message) :
                    $is_receiver = ($message->receiver_id == get_current_user_id());
                    $sender_posts = get_posts([
                      'post_type' => 'sellers',
                      'author' => $message->sender_id,
                      'numberposts' => 1
                    ]);
                    $sender_profile_image_id = !empty($sender_posts) ? $sender_posts[0]->ID : 0;
                    $seller_profile_attachment = $sender_profile_image_id ? get_post_meta($sender_profile_image_id, 'seller_profile_attachment', true) : '';
                    $sender_image = $seller_profile_attachment ? wp_get_attachment_image(prolancer_get_image_id($seller_profile_attachment), ['60', '60'], false) : '';
                    ?>
                    <div class="chat-list <?php echo $is_receiver ? '' : 'message_sender'; ?>">
                      <div class="row">
                        <?php if ($is_receiver) : ?>
                          <div class="col-3">
                            
                              <?php if (!empty($sender_image)) : 
                                echo wp_kses($sender_image, [
                                  'img' => [
                                    'src' => [],
                                    'alt' => [],
                                    'style' => [],
                                    'class' => [],
                                    'width' => [],
                                    'height' => []
                                  ]
                                ]);
                              else : 
                                echo get_avatar($message->sender_id, 60);
                              endif; ?>
                            
                          </div>
                          <div class="col-9">
                            <p><?php echo pcu_message_text( $message->message ); ?></p>
                          </div>
                        <?php else : ?>
                          <div class="col-9">
                            <p><?php echo pcu_message_text( $message->message ); ?></p>
                          </div>
                          <div class="col-3 text-end">
                            
                              <?php if (!empty($sender_image)) : 
                                echo wp_kses($sender_image, [
                                  'img' => [
                                    'src' => [],
                                    'alt' => [],
                                    'style' => [],
                                    'class' => [],
                                    'width' => [],
                                    'height' => []
                                  ]
                                ]);
                              else : 
                                echo get_avatar($message->sender_id, 60);
                              endif; ?>
                            
                          </div>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
                <form id="reply-message-form">
                  <div class="row">
                    <div class="col-2 text-center">
                      
                        <?php
                        $current_user_posts = get_posts([
                          'post_type' => 'sellers',
                          'author' => get_current_user_id(),
                          'numberposts' => 1
                        ]);
                        $current_user_image_id = !empty($current_user_posts) ? $current_user_posts[0]->ID : 0;
                        $current_user_attachment = $current_user_image_id ? get_post_meta($current_user_image_id, 'seller_profile_attachment', true) : '';
                        $current_user_image = $current_user_attachment ? wp_get_attachment_image(prolancer_get_image_id($current_user_attachment), ['60', '60'], false) : '';
                        
                        if (!empty($current_user_image)) : 
                          echo wp_kses($current_user_image, [
                            'img' => [
                              'src' => [],
                              'alt' => [],
                              'style' => [],
                              'class' => [],
                              'width' => [],
                              'height' => []
                            ]
                          ]);
                        else : 
                          echo get_avatar(get_current_user_id(), 60);
                        endif; ?>
                      
                    </div>
                    <div class="col-10">
                      <textarea name="message" placeholder="<?php echo esc_attr__('Type your message here...', 'prolancer'); ?>"></textarea>
                      <a href="#" class="send-message prolancer-btn mt-4" 
                         data-nonce="<?php echo esc_attr(wp_create_nonce('messages_nonce')); ?>" 
                         data-sender-id="<?php echo (int)get_current_user_id(); ?>" 
                         data-receiver-id="<?php echo (int)$chat_member; ?>">
                        <?php echo esc_html__('Send message', 'prolancer'); ?>
                      </a>
                    </div>
                  </div>
                </form>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    <?php } else { ?>
      <p class="mb-0"><?php echo esc_html__('No Message found!', 'prolancer'); ?></p>
    <?php } 
  } ?> 
</div>