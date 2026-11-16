jQuery(document).ready(function ($) {
  const container = $("#bbe-booking-container");
  if (!container.length) return;

  // Capture both parent product ID and the selected variation ID from the wrapper
  const productId = container.data("product-id");
  const variationId = container.data("variation-id");

  // Start from next week or current week's Monday, but ensure we don't allow going into past weeks
  let currentMonday = getNextValidMonday(new Date());

  const locationSelect = $("#bbe-location-select");

  // Helper function to safely get the location ID whether it's a dropdown or hidden input
  function getLocationId() {
    const el = $("#bbe-location-select");
    if (!el.length) return "";
    if (el.is("select")) {
      return el.val();
    }
    return el.val() || el.attr("value") || "";
  }

  const cartForm = $("#bbe-cart-form");
  cartForm.hide();

  // Use the robust helper function on initial load
  const initialLocationId = getLocationId();
  if (initialLocationId) {
    $(".bbe-step-calendar").show();
    loadWeekSlots(currentMonday);
  }

  locationSelect.on("change", function () {
    $("#bbe_input_start_time").val("");
    $("#bbe_input_location_id").val("");
    $(".bbe-slot-btn").removeClass("selected");
    cartForm.hide();

    const currentLocId = getLocationId();
    if (currentLocId) {
      $(".bbe-step-calendar").show();
      loadWeekSlots(currentMonday);
    } else {
      $(".bbe-step-calendar").hide();
    }
  });

  $("#bbe-next-week").on("click", function () {
    currentMonday.setDate(currentMonday.getDate() + 7);
    loadWeekSlots(currentMonday);
  });

  $("#bbe-prev-week").on("click", function () {
    let testDate = new Date(currentMonday);
    testDate.setDate(testDate.getDate() - 7);

    // Do not allow navigating to a week whose Monday is before today
    let today = new Date();
    today.setHours(0, 0, 0, 0);

    if (testDate >= getNextValidMonday(today) || testDate.getTime() >= today.getTime()) {
      currentMonday = testDate;
      loadWeekSlots(currentMonday);
    } else {
      alert("You cannot book sessions in the past.");
    }
  });

  function loadWeekSlots(mondayDate) {
    const locationId = getLocationId();
    if (!locationId) return;

    const dateStr = formatDate(mondayDate);
    $("#bbe-slots-grid").html('<p style="text-align:center;">Loading available slots...</p>');

    $.ajax({
      url: bbe_vars.ajax_url,
      type: "POST",
      data: {
        action: "bbe_get_slots",
        nonce: bbe_vars.nonce,
        product_id: productId,
        variation_id: variationId,
        location_id: locationId,
        start_date: dateStr,
      },
      success: function (response) {
        if (response.success) {
          $("#bbe-date-range-label").text(response.data.range);
          renderSlotsGrid(response.data.week_days);
        } else {
          $("#bbe-slots-grid").html("<p>Error loading slots.</p>");
        }
      },
      error: function (xhr, status, error) {
        $("#bbe-slots-grid").html("<p>Connection error loading slots.</p>");
      },
    });
  }

  function renderSlotsGrid(weekDays) {
    const grid = $("#bbe-slots-grid");
    grid.empty();

    let todayStr = formatDate(new Date());

    weekDays.forEach((day) => {
      let col = $('<div class="bbe-day-column">');
      col.append(`<div class="bbe-day-title">${day.formatted}</div>`);

      if (day.date <= todayStr || day.slots.length === 0) {
        col.append('<div class="bbe-no-slots">No slots</div>');
      } else {
        day.slots.forEach((slot) => {
          let btn = $(`<button type="button" class="bbe-slot-btn" data-start="${slot.start}">${slot.display}</button>`);

          btn.on("click", function () {
            $(".bbe-slot-btn").removeClass("selected");
            $(this).addClass("selected");

            createTemporaryHold(productId, variationId, getLocationId(), slot.start, day.formatted + " at " + slot.display);
          });

          col.append(btn);
        });
      }
      grid.append(col);
    });
  }

  function createTemporaryHold(prodId, varId, locId, startTime, displayLabel) {
    $.ajax({
      url: bbe_vars.ajax_url,
      type: "POST",
      data: {
        action: "bbe_create_hold",
        product_id: prodId,
        variation_id: varId,
        location_id: locId,
        start_time: startTime,
      },
      success: function (res) {
        if (res.success) {
          $("#bbe_input_start_time").val(startTime);
          $("#bbe_input_location_id").val(locId);
          $("#bbe-summary-text").text(displayLabel);
          cartForm.fadeIn();
        } else {
          alert("This slot was just taken. Please choose another.");
          cartForm.hide();
          loadWeekSlots(currentMonday);
        }
      },
    });
  }

  cartForm.on("submit", function (e) {
    const loc = getLocationId();
    const start = $("#bbe_input_start_time").val();
    if (!loc || !start) {
      e.preventDefault();
      alert("Please select both a location and a time slot before adding to cart.");
      return false;
    }
  });

  function getNextValidMonday(d) {
    d = new Date(d);
    let day = d.getDay();
    let diff = d.getDate() - day + (day === 0 ? -6 : 1);
    let monday = new Date(d.setDate(diff));
    monday.setHours(0, 0, 0, 0);

    let today = new Date();
    today.setHours(0, 0, 0, 0);

    if (monday < today) {
      monday.setDate(monday.getDate() + 7);
    }
    return monday;
  }

  function formatDate(d) {
    let month = "" + (d.getMonth() + 1),
      day = "" + d.getDate(),
      year = d.getFullYear();
    if (month.length < 2) month = "0" + month;
    if (day.length < 2) day = "0" + day;
    return [year, month, day].join("-");
  }
});
