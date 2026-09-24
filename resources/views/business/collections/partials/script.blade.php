{{--
    The collect dialog's own state, shared by the pages that offer it: the
    collection round, and a day's own invoice rows. One implementation, so the
    preview on one screen can never disagree with the other.
--}}
<script>
            function collections() {
                return {
                    open: null,
                    taking: null,
                    amount: '',

                    collect(customer) {
                        this.taking = customer;
                        this.amount = '';
                        this.$nextTick(() => this.$refs.amount?.focus());
                    },

                    /** The bill the collector went out for, settled first. */
                    get target() {
                        return this.taking?.bills?.[0] ?? null;
                    },

                    money(v) {
                        return (Number(v) || 0).toLocaleString('en-US', {
                            minimumFractionDigits: 2, maximumFractionDigits: 2,
                        });
                    },

                    /**
                     * What this money would settle, worked out exactly as the
                     * server will work it out — the named bill first, then the
                     * oldest — so the screen never promises something else.
                     */
                    get applied() {
                        let left = Number(this.amount) || 0;
                        const rows = [];

                        for (const bill of this.taking?.bills ?? []) {
                            if (left <= 0) break;
                            const applied = Math.min(left, bill.owed);
                            rows.push({ ...bill, applied, settled: applied >= bill.owed });
                            left -= applied;
                        }

                        return rows;
                    },

                    get onAccount() {
                        const used = this.applied.reduce((n, r) => n + r.applied, 0);
                        return Math.max(0, (Number(this.amount) || 0) - used);
                    },

                    get remaining() {
                        return Math.max(0, (this.taking?.owed ?? 0) - (Number(this.amount) || 0));
                    },
                };
            }
        </script>
