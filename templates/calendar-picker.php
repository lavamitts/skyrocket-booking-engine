
<div id="bbe-booking-widget" data-product-id="<?php echo get_the_ID(); ?>">
    <!-- Location Switcher (Hidden automatically if only 1 location exists) -->
    <div id="bbe-location-selector"></div>

    <!-- Calendar View Navigation -->
    <div class="bbe-calendar-controls">
        <button id="bbe-prev-week">&laquo; Previous Week</button>
        <span id="bbe-current-range"></span>
        <button id="bbe-next-week">Next Week &raquo;</button>
    </div>

    <!-- AJAX loaded slots grid -->
    <div id="bbe-slots-container"></div>

    <!-- Add to Cart Data -->
    <input type="hidden" name="bbe_start_time" id="bbe_selected_start">
    <input type="hidden" name="bbe_location_id" id="bbe_selected_location">
</div>