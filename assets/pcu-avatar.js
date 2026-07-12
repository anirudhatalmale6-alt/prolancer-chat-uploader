/**
 * Profile picture — pick, crop (1:1), upload, show at once.
 * ----------------------------------------------------------------------------
 * The round camera control (pcu_avatar_uploader) opens the file picker; a chosen
 * image opens the Cropper.js modal; Upload crops to a square, sends it through
 * the plugin's own upload endpoint, and drops the result straight into the
 * control and the sidebar — no page reload.
 *
 * The endpoint (prolancer_ajax_upload_file) is the plugin's, unchanged. The
 * profile form already saves whatever id sits in the hidden <input>, so writing
 * the new id there is all that is needed to persist the picture on save.
 *
 * Depends on Cropper (loaded first). No jQuery.
 */
(function (window, document) {
    'use strict';

    var CFG = window.PCU_AVATAR || {};
    var cropper = null;
    var activeUploader = null;   // the .pcu-avatar-upload the modal is working for

    var modal = document.querySelector('.pcu-crop-modal');
    if (!modal || !window.Cropper) { return; }

    var image = modal.querySelector('.pcu-crop-image');
    var btnSave = modal.querySelector('.pcu-crop-save');
    var btnClose = modal.querySelector('.pcu-crop-close');

    // ------------------------------------------------------------- open / close

    function openPicker(uploader) {
        var input = uploader.querySelector('.pcu-avatar-file');
        if (input) { input.click(); }
    }

    function openModal(uploader, file) {
        activeUploader = uploader;

        var reader = new FileReader();
        reader.onload = function () {
            image.src = reader.result;

            modal.hidden = false;
            document.body.classList.add('pcu-crop-open');

            if (cropper) { cropper.destroy(); }

            cropper = new window.Cropper(image, {
                aspectRatio: 1,          // the brief: 1:1

                // viewMode 2, not 1: the whole picture is fitted INSIDE the box,
                // so it is never cut off by the modal and never needs a scrollbar.
                viewMode: 2,

                // 0.8, not 1. At 1 the crop box starts the same size as the
                // picture, so there is nothing to drag and nothing to resize —
                // which makes a crop tool pointless. Starting smaller leaves room
                // to move it and pull its corners.
                autoCropArea: 0.8,

                background: false,
                movable: true,           // drag the picture under the box
                zoomable: true,          // and scroll to zoom it
                cropBoxMovable: true,    // or drag the box itself
                cropBoxResizable: true,  // and resize it by the handles
                rotatable: false,
                scalable: false,
                responsive: true,
                dragMode: 'move'
            });
        };
        reader.readAsDataURL(file);
    }

    function closeModal() {
        if (cropper) { cropper.destroy(); cropper = null; }

        modal.hidden = true;
        document.body.classList.remove('pcu-crop-open');

        // Let the same file be chosen again next time.
        if (activeUploader) {
            var input = activeUploader.querySelector('.pcu-avatar-file');
            if (input) { input.value = ''; }
        }
        image.removeAttribute('src');
        activeUploader = null;
    }

    // ------------------------------------------------------------------- upload

    function spin(uploader, on) {
        var s = uploader.querySelector('.pcu-avatar-spin');
        if (s) { s.hidden = !on; }
        uploader.classList.toggle('is-busy', !!on);
        btnSave.disabled = !!on;
        btnClose.disabled = !!on;
    }

    /**
     * Paint the cropped picture everywhere it shows, at once — the control here,
     * and the big avatar in the dashboard sidebar. Uses the local cropped image,
     * so it appears the instant the upload succeeds, not after a re-fetch.
     */
    function showEverywhere(uploader, dataUrl) {
        var photo = uploader.querySelector('.pcu-avatar-photo');
        if (photo) {
            photo.src = dataUrl;
            photo.hidden = false;
        }
        uploader.classList.add('has-image');

        // The sidebar's profile picture is the same image; keep it in step.
        var side = document.querySelector('.feds-user-profile img');
        if (side) { side.src = dataUrl; }
    }

    function upload(uploader, blob) {
        var data = new FormData();
        data.append('action', CFG.action || 'prolancer_ajax_upload_file');
        data.append('nonce', uploader.getAttribute('data-nonce'));
        data.append('post_id', uploader.getAttribute('data-post-id'));
        // The endpoint reads $_FILES['file']. A Blob needs a filename to arrive
        // as a file rather than a string.
        data.append('file', blob, 'profile.png');

        spin(uploader, true);

        return window.fetch(CFG.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: data
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.success || !res.data || !res.data.id) {
                    throw new Error('upload failed');
                }

                // Persist: the profile form saves whatever id is in here.
                var idInput = uploader.querySelector('.pcu-avatar-id');
                if (idInput) { idInput.value = res.data.id; }

                showEverywhere(uploader, image.src);   // the cropped data URL
                spin(uploader, false);
                closeModal();
            })
            .catch(function () {
                spin(uploader, false);
                // Keep the modal open so the crop is not lost; tell them plainly.
                window.alert('Sorry — the picture could not be uploaded. Please try again.');
            });
    }

    // --------------------------------------------------------------- wiring

    // Click the ring -> open the file picker. Delegated, so more than one
    // uploader on a page (profile picture + anything added later) just works.
    document.addEventListener('click', function (e) {
        var ring = e.target.closest && e.target.closest('.pcu-avatar-ring');
        if (ring) {
            e.preventDefault();
            var uploader = ring.closest('.pcu-avatar-upload');
            if (uploader && !uploader.classList.contains('is-busy')) { openPicker(uploader); }
        }
    });

    document.addEventListener('change', function (e) {
        if (!e.target.classList || !e.target.classList.contains('pcu-avatar-file')) { return; }

        var file = e.target.files && e.target.files[0];
        if (!file) { return; }

        if (!/^image\//.test(file.type)) {
            window.alert('Please choose an image file.');
            e.target.value = '';
            return;
        }

        openModal(e.target.closest('.pcu-avatar-upload'), file);
    });

    btnSave.addEventListener('click', function () {
        if (!cropper || !activeUploader) { return; }

        var canvas = cropper.getCroppedCanvas({
            width: 512,
            height: 512,
            imageSmoothingQuality: 'high'
        });

        // Show the cropped result in the control the moment it is uploaded.
        image.src = canvas.toDataURL('image/png');

        var uploader = activeUploader;
        canvas.toBlob(function (blob) {
            if (blob) { upload(uploader, blob); }
        }, 'image/png');
    });

    // Closes ONLY by the button — no backdrop-click binding, by design.
    btnClose.addEventListener('click', function () {
        if (!btnClose.disabled) { closeModal(); }
    });

    // Esc is a convenience the brief did not forbid; still blocked while busy.
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden && !btnClose.disabled) { closeModal(); }
    });

}(window, document));
