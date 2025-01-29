/**
 * @author Iulian Ceapa <dev@webspecto.com>
 * @copyright © 2023-2025 WebSpecto.
 */

jQuery(document).ready(function ($) {
    $('.tab-link').click(function (e) {
        let tab_id = $(this).attr('data-tab');

        $('.tab-link').removeClass('active');
        $('.tab-content').removeClass('active-tab').hide();

        $(this).addClass('active');
        $('#' + tab_id).addClass('active-tab').show();
    });

    function toggleOptgroups(pipeline_id) {
        $('#status optgroup').each(function () {
            $(this).prop('disabled', $(this).attr('id') !== pipeline_id);
        });
    }

    toggleOptgroups($('#pipeline').val());

    $('#pipeline').on('change', function () {
        toggleOptgroups($(this).val());

        $('#status optgroup').each(function () {
            if (!$(this).prop('disabled')) {
                $(this).find('option').first().prop('selected', true);
                return false;
            }
        });
    });
});