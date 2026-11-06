/* Script for resources/views/partials/room-form-staff.blade.php. */
          /* show the chosen location's staff; the ids ticked there go with the save */
          (function () {
            var trigger = document.querySelector('#roomLoc .vm-dd-trigger');
            function show() {
              var office = trigger ? trigger.textContent.trim() : '';
              var any = false;
              document.querySelectorAll('#rfStaff .rf-staff-office').forEach(function (box) {
                box.hidden = box.dataset.office !== office;
                any = any || !box.hidden;
              });
              document.querySelector('#rfStaff .rf-staff-hint').hidden = any;
            }
            document.querySelectorAll('#roomLoc .vm-dd-option').forEach(function (opt) {
              opt.addEventListener('click', function () { setTimeout(show, 0); });
            });
            show();
            window.rfHallStaff = function () {
              return Array.prototype.map.call(document.querySelectorAll('#rfStaff .rf-staff-office:not([hidden]) .rf-staff-pick:checked'),
                function (box) { return Number(box.value); });
            };
          })();
        
