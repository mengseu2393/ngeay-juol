// /m property screens — Alpine.data() registrations live here (see resources/js/mobile.js).

// ── Add property (livewire.mobile.add-property) ─────────────────────────────
//
// The room preview under "Floors × rooms per floor": the same numbers
// PropertySetupService::roomGrid() will create (floor + two-digit sequence:
// 101, 102 · 201 …), worked out here so typing costs no request.

document.addEventListener('alpine:init', () => {
    window.Alpine.data('rwMobileAddProperty', (roomsWord) => ({
        roomNumber(floor, seq) {
            return `${floor}${String(seq).padStart(2, '0')}`;
        },

        get roomTotal() {
            const floors = parseInt(this.$wire.floors, 10) || 0;
            const perFloor = parseInt(this.$wire.perFloor, 10) || 0;

            return floors > 0 && perFloor > 0 ? floors * perFloor : 0;
        },

        get roomPreview() {
            const floors = Math.min(parseInt(this.$wire.floors, 10) || 0, 50);
            const perFloor = Math.min(parseInt(this.$wire.perFloor, 10) || 0, 100);

            if (floors < 1 || perFloor < 1) {
                return '';
            }

            const rows = [];
            for (let floor = 1; floor <= Math.min(floors, 3); floor++) {
                rows.push(perFloor === 1
                    ? this.roomNumber(floor, 1)
                    : `${this.roomNumber(floor, 1)}–${this.roomNumber(floor, perFloor)}`);
            }

            if (floors > 3) {
                rows.push('…');
            }

            return `${this.roomTotal} ${roomsWord}: ${rows.join(' · ')}`;
        },
    }));
});
