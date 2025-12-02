jQuery(document).ready(function($) {
    console.log('FC AWB JavaScript loaded');
    
    // Handle Generate AWB button click
    $(document).on('click', '.hgezlpfcr-generate-awb-btn', function(e) {
        e.preventDefault();
        console.log('Generate AWB button clicked');
        
        var $btn = $(this);
        var $container = $btn.closest('.hgezlpfcr-awb-actions');
        var $status = $container.find('.hgezlpfcr-awb-status');
        var orderId = $btn.data('order-id');
        
        console.log('Order ID:', orderId);
        console.log('AJAX URL:', hgezlpfcr_awb_ajax.ajax_url);
        console.log('Nonce:', hgezlpfcr_awb_ajax.nonce);
        
        // Disable button and show loading
        $btn.prop('disabled', true).text(hgezlpfcr_awb_ajax.generating_text);
        $status.removeClass('notice-success notice-error').addClass('notice notice-info').html('<p>' + hgezlpfcr_awb_ajax.generating_text + '</p>').show();
        
        // Make AJAX request
        $.ajax({
            url: hgezlpfcr_awb_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'hgezlpfcr_generate_awb_ajax',
                order_id: orderId,
                nonce: hgezlpfcr_awb_ajax.nonce
            },
            success: function(response) {
                console.log('AJAX response:', response);
                if (response.success) {
                    // Show success message
                    $status.removeClass('notice-info notice-error').addClass('notice-success').html('<p>' + response.data.message + '</p>');
                    
                    // Update the container with new HTML
                    $container.html(response.data.html);
                    
                    // Show success message briefly then hide
                    setTimeout(function() {
                        $status.fadeOut();
                    }, 3000);
                    
                } else {
                    // Show error message
                    $status.removeClass('notice-info notice-success').addClass('notice-error').html('<p>' + (response.data || hgezlpfcr_awb_ajax.error_text) + '</p>');
                    
                    // Re-enable button
                    $btn.prop('disabled', false).text('🚚 Generează AWB');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', {xhr: xhr, status: status, error: error});
                // Show error message
                $status.removeClass('notice-info notice-success').addClass('notice-error').html('<p>' + hgezlpfcr_awb_ajax.error_text + ': ' + error + '</p>');
                
                // Re-enable button
                $btn.prop('disabled', false).text('🚚 Generează AWB');
            }
        });
    });
    
    // Handle Sync AWB button click
    $(document).on('click', '.fc-sync-awb-btn', function(e) {
        e.preventDefault();
        console.log('Sync AWB button clicked');

        var $btn = $(this);
        var $container = $btn.closest('.hgezlpfcr-awb-actions');
        var $status = $container.find('.hgezlpfcr-awb-status');
        var orderId = $btn.data('order-id');
        var nonce = $btn.data('nonce');

        console.log('Order ID:', orderId);
        console.log('Nonce:', nonce);

        // Check if button is already disabled (rate limiting in progress)
        if ($btn.prop('disabled')) {
            console.log('Button is disabled - rate limiting active');
            return;
        }

        // Disable button and show loading
        var originalText = $btn.text();
        $btn.prop('disabled', true).text('Sincronizare...');
        $status.removeClass('notice-success notice-error').addClass('notice notice-info').html('<p>Verificare AWB în FanCourier...</p>').show();
        
        // Make AJAX request
        $.ajax({
            url: hgezlpfcr_awb_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'hgezlpfcr_sync_awb',
                order_id: orderId,
                nonce: nonce
            },
            success: function(response) {
                console.log('Sync AJAX response:', response);
                if (response.success) {
                    // Show success message
                    $status.removeClass('notice-info notice-error').addClass('notice-success').html('<p>' + response.data.message + '</p>');

                    // Update the container with new HTML
                    $container.html(response.data.html);

                    // Show success message briefly then hide
                    setTimeout(function() {
                        $status.fadeOut();
                    }, 5000);

                } else {
                    // Show error message
                    $status.removeClass('notice-info notice-success').addClass('notice-error').html('<p>' + (response.data || 'Eroare la sincronizarea AWB') + '</p>');

                    // Rate limiting: Re-enable button after 5 seconds
                    setTimeout(function() {
                        var $newBtn = $('.fc-sync-awb-btn[data-order-id="' + orderId + '"]');
                        if ($newBtn.length) {
                            $newBtn.prop('disabled', false);
                        }
                    }, 5000);
                }
            },
            error: function(xhr, status, error) {
                console.error('Sync AJAX error:', {xhr: xhr, status: status, error: error});
                // Show error message
                $status.removeClass('notice-info notice-success').addClass('notice-error').html('<p>Eroare la sincronizarea AWB: ' + error + '</p>');

                // Rate limiting: Re-enable button after 5 seconds
                setTimeout(function() {
                    var $newBtn = $('.fc-sync-awb-btn[data-order-id="' + orderId + '"]');
                    if ($newBtn.length) {
                        $newBtn.prop('disabled', false);
                    } else {
                        $btn.prop('disabled', false).text(originalText);
                    }
                }, 5000);
            }
        });
    });
});
