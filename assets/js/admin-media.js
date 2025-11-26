(function ($) {
    $(function () {
        var frame;
        var $logoId = $('#scs_mode_logo_id');
        var $preview = $('#scs-mode-logo-preview');
        var $empty = $('#scs-mode-logo-empty');
        var $removeButton = $('#scs-mode-remove-logo');

        $('#scs-mode-select-logo').on('click', function (event) {
            event.preventDefault();

            if (frame) {
                frame.open();
                return;
            }

            frame = wp.media({
                title: 'Select a logo',
                button: { text: 'Use this logo' },
                multiple: false,
            });

            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON();
                $logoId.val(attachment.id);
                $preview.attr('src', attachment.url).show();
                $empty.hide();
                $removeButton.prop('disabled', false);
            });

            frame.open();
        });

        $removeButton.on('click', function (event) {
            event.preventDefault();
            $logoId.val('');
            $preview.hide().attr('src', '');
            $empty.show();
            $removeButton.prop('disabled', true);
        });
    });
})(jQuery);
