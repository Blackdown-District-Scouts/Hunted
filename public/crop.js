// Photo cropping for the roster form.
//  - Choosing a file opens a square cropper.
//  - "Apply crop" stores the cropped image (as a data URL) + the crop rectangle
//    in hidden fields; the original file is uploaded alongside.
//  - "Edit crop" reopens the cropper on the stored original, restoring the
//    previous crop rectangle so it can be adjusted.
(function () {
  var field = document.getElementById('photoField');
  if (!field || typeof Cropper === 'undefined') return;

  var fileInput   = document.getElementById('photoInput');
  var editBtn     = document.getElementById('editCropBtn');
  var preview     = document.getElementById('photoPreview');
  var croppedHid  = document.getElementById('croppedImage');
  var cropDataHid = document.getElementById('cropData');

  var modal   = document.getElementById('cropModal');
  var cropImg = document.getElementById('cropImage');
  var applyBtn  = document.getElementById('cropApply');
  var cancelBtn = document.getElementById('cropCancel');

  var cropper = null;
  var objectUrl = null;

  function destroyCropper() {
    if (cropper) { cropper.destroy(); cropper = null; }
  }
  function revokeUrl() {
    if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
  }

  // Open the cropper on a source URL. Does NOT revoke objectUrl — callers manage
  // that, so the freshly-created blob URL stays valid while it's displayed.
  function openCropper(src, cropData) {
    destroyCropper();
    cropImg.src = src;
    modal.hidden = false;
    cropper = new Cropper(cropImg, {
      aspectRatio: 1,
      viewMode: 1,
      autoCropArea: 1,
      background: false,
      ready: function () {
        if (cropData) { try { cropper.setData(cropData); } catch (e) {} }
      }
    });
  }

  function closeCropper() {
    destroyCropper();
    modal.hidden = true;
    revokeUrl();
  }

  fileInput.addEventListener('change', function () {
    var f = fileInput.files && fileInput.files[0];
    if (!f) return;
    revokeUrl();                          // drop any previous selection's URL
    objectUrl = URL.createObjectURL(f);   // keep the new one alive
    openCropper(objectUrl, null);
  });

  if (editBtn) {
    editBtn.addEventListener('click', function () {
      // Prefer the stored original; fall back to the current (already-cropped) photo
      // for older entries that have no original saved.
      var src = field.dataset.original || field.dataset.photo;
      if (!src) return;
      revokeUrl();                        // switching away from any file selection
      // Only restore the saved crop rectangle when editing the true original —
      // the rectangle is in the original's coordinates, not the cropped photo's.
      var data = null;
      if (field.dataset.original && field.dataset.crop) {
        try { data = JSON.parse(field.dataset.crop); } catch (e) {}
      }
      openCropper(src, data);
    });
  }

  applyBtn.addEventListener('click', function () {
    if (!cropper) return;
    // Keep the crop at its native resolution (no downscale). The cap only guards
    // against the browser's hard canvas-size limit on very large images.
    var canvas = cropper.getCroppedCanvas({
      maxWidth: 4096,
      maxHeight: 4096,
      imageSmoothingEnabled: true,
      imageSmoothingQuality: 'high'
    });
    if (canvas) {
      croppedHid.value = canvas.toDataURL('image/jpeg', 0.92);
      cropDataHid.value = JSON.stringify(cropper.getData(true));
      preview.src = croppedHid.value;
    }
    closeCropper();
  });

  cancelBtn.addEventListener('click', function () {
    closeCropper();
    fileInput.value = ''; // allow re-selecting the same file
  });
})();
