jQuery(document).ready(function ($) {
  // Expose setBbeView globally so inline onclick handlers or button triggers can call it
  window.setBbeView = function (mode) {
    $("#bbe-view-mode-input").val(mode);
    // Submit the parent toolbar filter form automatically
    $(".bbe-toolbar").submit();
  };
});
