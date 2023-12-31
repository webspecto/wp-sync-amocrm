/**
 * @author Iulian Ceapa <dev@webspecto.com>
 * @copyright © 2023 WebSpecto.
 */

jQuery(document).ready(function ($) {
    $(".tab-link").click(function () {
        var tab_id = $(this).attr("data-tab");

        $(".tab-link").removeClass("active");
        $(".tab-content").removeClass("active-tab").hide();

        $(this).addClass("active");
        $("#" + tab_id).addClass("active-tab").show();
    });
});