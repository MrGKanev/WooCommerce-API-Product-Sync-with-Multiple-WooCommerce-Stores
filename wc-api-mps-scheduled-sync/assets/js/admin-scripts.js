/**
 * WC API MPS Scheduled Sync - Admin Scripts (Simplified)
 */

(function($) {
  'use strict';

  $(document).ready(function() {
    
    // Select/Deselect all stores
    $('.wc-api-mps-select-all-stores').on('change', function() {
      const checked = $(this).prop('checked');
      $('.wc-api-mps-store-item input[type="checkbox"]:not(:disabled)').prop('checked', checked);
    });

  });

})(jQuery);