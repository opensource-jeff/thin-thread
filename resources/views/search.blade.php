<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thin Thread Search</title>
    <script src="https://cdn.tailwindcss.com"></script>
    @include('partials.thread-ui')
</head>
<body class="thread-shell">
    <div class="thread-page min-h-screen">
        <header class="border-b border-slate-800/80 bg-black/20">
            <div class="mx-auto flex max-w-7xl flex-col gap-5 px-4 py-5 sm:px-6 lg:px-8 md:flex-row md:items-center md:justify-between">
                <div class="flex min-w-0 items-center gap-4">
                    <div class="thread-logo" aria-hidden="true">
                        <span></span>
                        <span></span>
                        <span></span>
                    </div>
                    <div class="min-w-0">
                        <p class="thread-kicker">DuckDB capsule mesh</p>
                        <h1 class="mt-1 text-3xl font-bold text-white sm:text-4xl">Thin Thread</h1>
                    </div>
                </div>

                <nav class="flex flex-wrap items-center gap-3">
                    <span class="status-pill">
                        <span class="status-dot" aria-hidden="true"></span>
                        Search online
                    </span>
                    @if (auth()->user()?->is_admin)
                        <a href="/admin" class="thread-link">Admin</a>
                    @endif
                    <form action="{{ route('logout') }}" method="POST" class="inline">
                        @csrf
                        <button type="submit" class="thread-danger">Logout</button>
                    </form>
                </nav>
            </div>
        </header>

        <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            <section class="search-surface">
                <div class="border-b border-slate-800/80 px-4 py-4 sm:px-6">
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                        <div>
                            <p class="thread-kicker">Search workspace</p>
                            <h2 class="mt-1 text-2xl font-bold text-white sm:text-3xl">Federated capsule search</h2>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <span class="status-pill">
                                <span id="status">Idle</span>
                            </span>
                            <span id="counter" class="status-pill hidden text-teal-100">Hits: 0</span>
                            <span id="capsule-counter" class="status-pill hidden">Capsules: 0</span>
                        </div>
                    </div>
                </div>

                <div class="px-4 py-5 sm:px-6">
                    <form id="search-form" class="search-input-wrap">
                        <div class="relative">
                            <input type="text" id="query" autocomplete="off" placeholder="Search names, handles, domains, tokens..."
                                   class="thread-input pr-16">
                            <div id="loader" class="absolute right-5 top-1/2 hidden -translate-y-1/2">
                                <svg class="h-7 w-7 animate-spin text-teal-300" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                            </div>
                        </div>
                        <button type="submit" class="search-submit">
                            Search
                        </button>
                    </form>

                    <div class="mt-5 grid gap-3 md:grid-cols-4">
                        <div class="thread-metric">
                            <span>Engine</span>
                            <strong>DuckDB FTS</strong>
                        </div>
                        <div class="thread-metric">
                            <span>Scope</span>
                            <strong>All .db capsules</strong>
                        </div>
                        <div class="thread-metric">
                            <span>Mode</span>
                            <strong>Streaming results</strong>
                        </div>
                        <div class="thread-metric">
                            <span>Limit</span>
                            <strong>100 hits per capsule</strong>
                        </div>
                    </div>
                </div>
            </section>

            <section class="mt-8">
                <div class="result-toolbar flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-sm font-bold text-white" id="result-title">Results</p>
                        <p class="mt-1 text-sm thread-muted" id="result-subtitle">Awaiting query</p>
                    </div>
                    <div class="text-sm thread-muted" id="result-total">0 hits</div>
                </div>

                <div id="idle-state" class="thread-panel mt-4 px-6 py-16 text-center">
                    <div class="mx-auto mb-4 h-px max-w-xs bg-gradient-to-r from-transparent via-slate-500/50 to-transparent"></div>
                    <p class="text-base font-semibold text-white">No search running</p>
                    <p class="mt-2 text-sm thread-muted">Thin Thread is ready to scan the loaded DuckDB capsules.</p>
                </div>

                <div id="results" class="mt-4 space-y-4">
                    <!-- Results will be injected here -->
                </div>

                <div id="no-results" class="thread-panel mt-4 hidden px-6 py-16 text-center">
                    <div class="mx-auto mb-4 h-px max-w-xs bg-gradient-to-r from-transparent via-teal-300/40 to-transparent"></div>
                    <p class="text-base font-semibold text-white">No matching thread found</p>
                    <p class="mt-2 text-sm thread-muted">No loaded DuckDB capsule returned hits for this query.</p>
                </div>
            </section>
        </main>
    </div>

    <script>
        let eventSource = null;
        let totalHits = 0;
        let capsuleCount = 0;

        document.getElementById('search-form').addEventListener('submit', function (e) {
            e.preventDefault();
            startSearch();
        });

        function startSearch() {
            const queryInput = document.getElementById('query');
            const query = queryInput.value.trim();
            if (!query) return;

            if (eventSource) {
                eventSource.close();
            }

            totalHits = 0;
            capsuleCount = 0;
            document.getElementById('results').innerHTML = '';
            document.getElementById('idle-state').classList.add('hidden');
            document.getElementById('no-results').classList.add('hidden');
            document.getElementById('loader').classList.remove('hidden');
            document.getElementById('status').innerText = 'Scanning';
            document.getElementById('counter').classList.remove('hidden');
            document.getElementById('counter').innerText = 'Hits: 0';
            document.getElementById('capsule-counter').classList.remove('hidden');
            document.getElementById('capsule-counter').innerText = 'Capsules: 0';
            document.getElementById('result-title').innerText = `Results for "${query}"`;
            document.getElementById('result-subtitle').innerText = 'Streaming matching rows from DuckDB capsules';
            document.getElementById('result-total').innerText = '0 hits';

            eventSource = new EventSource(`/search/stream?q=${encodeURIComponent(query)}`);

            eventSource.addEventListener('ping', function(e) {
                const data = JSON.parse(e.data);
                capsuleCount += 1;
                document.getElementById('capsule-counter').innerText = `Capsules: ${capsuleCount}`;
                console.log(`Scanning capsule: ${data.capsule}`);
            });

            eventSource.addEventListener('meta', function(e) {
                const data = JSON.parse(e.data);
                renderCapsule(data);
            });

            eventSource.addEventListener('hit', function(e) {
                const data = JSON.parse(e.data);
                totalHits += 1;
                document.getElementById('counter').innerText = `Hits: ${totalHits}`;
                document.getElementById('result-total').innerText = `${totalHits} hits`;
                renderHit(data);
            });

            eventSource.addEventListener('done', function(e) {
                finalizeSearch();
            });

            eventSource.onerror = function(e) {
                console.error('EventSource failed:', e);
                finalizeSearch();
            };
        }

        function finalizeSearch() {
            document.getElementById('loader').classList.add('hidden');
            document.getElementById('status').innerText = 'Complete';
            document.getElementById('result-subtitle').innerText = totalHits > 0
                ? `${capsuleCount} capsules scanned`
                : 'No capsules returned matching rows';

            if (totalHits === 0) {
                document.getElementById('no-results').classList.remove('hidden');
            }
            if (eventSource) eventSource.close();
        }

        function renderCapsule(data) {
            const resultsDiv = document.getElementById('results');
            const capsuleId = `capsule-${data.capsule.replace(/[^a-z0-9]/gi, '-')}`;
            
            if (document.getElementById(capsuleId)) return;

            const capsuleSection = document.createElement('div');
            capsuleSection.id = capsuleId;
            capsuleSection.className = 'result-card thread-panel hidden'; // Hidden until first hit

            const displayName = escapeHtml(data.display_name || 'Untitled capsule');
            const leakDate = escapeHtml(data.leak_date || 'Unknown date');
            const totalLines = escapeHtml(data.total_lines || '0');
            const capsuleName = escapeHtml(data.capsule || 'capsule.db');

            capsuleSection.innerHTML = `
                <div class="result-head px-4 py-4 sm:px-5">
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                        <div class="min-w-0">
                            <p class="thread-kicker">DuckDB capsule</p>
                            <h2 class="mt-1 truncate text-xl font-bold text-white">${displayName}</h2>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <span class="db-pill">${capsuleName}</span>
                            <span class="rounded-full border border-slate-700/80 px-3 py-1 thread-muted">${leakDate}</span>
                            <span class="rounded-full border border-slate-700/80 px-3 py-1 thread-muted">${totalLines} lines</span>
                            <span class="hit-badge rounded-full border border-teal-400/30 bg-teal-400/10 px-3 py-1 text-teal-200">0 hits</span>
                        </div>
                    </div>
                </div>
                <div class="hits-container max-h-[32rem] overflow-y-auto">
                    <!-- Hits will be injected here -->
                </div>
            `;
            resultsDiv.appendChild(capsuleSection);
        }

        function renderHit(data) {
            const capsuleId = `capsule-${data.capsule.replace(/[^a-z0-9]/gi, '-')}`;
            const capsuleSection = document.getElementById(capsuleId);
            if (!capsuleSection) return;

            capsuleSection.classList.remove('hidden');
            const container = capsuleSection.querySelector('.hits-container');
            const badge = capsuleSection.querySelector('.hit-badge');
            
            const hitCount = container.children.length + 1;
            badge.innerText = `${hitCount} hits`;

            const hitDiv = document.createElement('div');
            hitDiv.className = 'hit-item grid gap-3 px-4 py-4 sm:grid-cols-[84px_1fr] sm:px-5';
            hitDiv.innerHTML = `
                <div class="hit-index">Match ${String(hitCount).padStart(2, '0')}</div>
                <div class="hit-copy break-all">${highlight(data.text, document.getElementById('query').value)}</div>
            `;
            container.appendChild(hitDiv);
        }

        function highlight(text, query) {
            const source = String(text ?? '');
            if (!query) return escapeHtml(source);

            const regex = new RegExp(`(${query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');

            return source
                .split(regex)
                .map((part, index) => index % 2 === 1 ? `<mark>${escapeHtml(part)}</mark>` : escapeHtml(part))
                .join('');
        }

        function escapeHtml(value) {
            const div = document.createElement('div');
            div.textContent = String(value ?? '');
            return div.innerHTML;
        }
    </script>
</body>
</html>
