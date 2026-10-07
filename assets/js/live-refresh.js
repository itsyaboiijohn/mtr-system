(function () {
    // Polls a tiny signature endpoint and runs onChange only when data actually changed.
    window.MTRLive = {
        start: function (onChange, options) {
            options = options || {};
            var url = options.url || '../api/live-status.php';
            var interval = options.interval || 8000;
            var isBusy = options.isBusy || function () { return false; };
            var last = null;
            var pending = false;
            var running = false;

            var typing = function () {
                var el = document.activeElement;
                return !!el && /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName);
            };

            var check = function () {
                if (running || document.hidden) return;
                running = true;
                fetch(url + '?_=' + Date.now(), { cache: 'no-store', credentials: 'same-origin' })
                    .then(function (response) { return response.ok ? response.json() : null; })
                    .then(function (data) {
                        if (!data || !data.signature) return;
                        if (last === null) { last = data.signature; return; }
                        if (data.signature !== last) pending = true;
                        if (pending && !typing() && !isBusy()) {
                            pending = false;
                            last = data.signature;
                            return onChange();
                        }
                    })
                    .catch(function () {})
                    .then(function () { running = false; });
            };

            setInterval(check, interval);
            document.addEventListener('visibilitychange', function () { if (!document.hidden) check(); });
            check();
        }
    };
})();
