jQuery(document).ready(function($) {
    // Listen for click events on elements with the class 'uta-track-click'
    $(document).on('click', '.uta-track-click', function(e) {
        var eventType = $(this).data('uta-event') || 'click';
        var eventData = {
            event: eventType,
            page: window.location.href,
            timestamp: new Date().toISOString()
        };

        $.ajax({
            url: uta_tracking_vars.ajax_url,
            method: 'POST',
            data: {
                action: 'uta_track_event',
                event_data: eventData,
                nonce: uta_tracking_vars.nonce
            },
            success: function(response) {
                // Optional: Log success or perform additional actions.
                console.log(response);
            },
            error: function(error) {
                console.log(error);
            }
        });
    });
});
