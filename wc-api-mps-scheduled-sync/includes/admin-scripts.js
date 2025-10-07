/**
 * WC API MPS Scheduled Sync - Admin Scripts
 */

(function($) {
  'use strict';

  $(document).ready(function() {
    
    // Collapsible sections
    $('.wc-api-mps-section-header').on('click', function() {
      const $header = $(this);
      const $content = $header.next('.wc-api-mps-section-content');
      
      $header.toggleClass('collapsed');
      $content.slideToggle(300);
      
      // Save state
      const sectionId = $header.parent().attr('id');
      if (sectionId) {
        const isCollapsed = $header.hasClass('collapsed');
        localStorage.setItem('wc_api_mps_section_' + sectionId, isCollapsed ? '1' : '0');
      }
    });

    // Restore collapsed states
    $('.wc-api-mps-section').each(function() {
      const $section = $(this);
      const sectionId = $section.attr('id');
      
      if (sectionId) {
        const isCollapsed = localStorage.getItem('wc_api_mps_section_' + sectionId);
        if (isCollapsed === '1') {
          $section.find('.wc-api-mps-section-header').addClass('collapsed');
          $section.find('.wc-api-mps-section-content').hide();
        }
      }
    });

    // Select/Deselect all stores
    $('.wc-api-mps-select-all-stores').on('change', function() {
      const checked = $(this).prop('checked');
      $('.wc-api-mps-store-item input[type="checkbox"]:not(:disabled)').prop('checked', checked);
    });

    // Confirm force sync
    $('input[name="force_sync_orders"]').on('click', function(e) {
      if (!confirm('This will sync all products from the last 15 orders immediately. Continue?')) {
        e.preventDefault();
        return false;
      }
    });

    // Auto-refresh status every 30 seconds (optional)
    const autoRefresh = false; // Set to true if you want auto-refresh
    if (autoRefresh) {
      setInterval(function() {
        location.reload();
      }, 30000);
    }

  });

})(jQuery);