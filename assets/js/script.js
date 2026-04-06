/**
 * @author Iulian Ceapa <dev@webspecto.com>
 * @copyright © 2023-2026 WebSpecto.
 */

jQuery(document).ready(function ($) {
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