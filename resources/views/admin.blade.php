<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thin Thread Intelligence</title>
    <script src="https://cdn.tailwindcss.com"></script>
    @include('partials.thread-ui')
</head>
<body class="thread-shell">
    <div class="thread-page mx-auto max-w-7xl px-4 py-5 sm:px-6 lg:px-8">
        <header class="flex flex-col gap-5 border-b border-slate-800/80 pb-5 md:flex-row md:items-center md:justify-between">
            <div class="flex min-w-0 items-center gap-4">
                <div class="thread-logo" aria-hidden="true">
                    <span></span>
                    <span></span>
                    <span></span>
                </div>
                <div class="min-w-0">
                    <p class="thread-kicker">Leaks and accounts</p>
                    <h1 class="mt-1 text-3xl font-bold text-white sm:text-4xl">Thin Thread Intelligence</h1>
                    <p class="mt-1 text-sm thread-muted">Manage leak ingestion and user access.</p>
                </div>
            </div>

            <nav class="flex flex-wrap items-center gap-3">
                <a href="/search" class="thread-link">Search</a>
                <form action="{{ route('logout') }}" method="POST" class="inline">
                    @csrf
                    <button type="submit" class="thread-danger">Logout</button>
                </form>
            </nav>
        </header>

        <main class="py-8">
            @if (session('status'))
                <div class="thread-alert thread-alert-info mb-5">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="thread-alert thread-alert-error mb-5">
                    <ul class="list-disc pl-5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
                <form action="/admin/ingest" method="POST" enctype="multipart/form-data" class="thread-panel p-4 sm:p-6 lg:p-8">
                    @csrf

                    <div class="mb-6">
                        <p class="thread-kicker">Leak ingest</p>
                        <h2 class="mt-1 text-2xl font-bold text-white">Ingest leak file</h2>
                    </div>

                    <div class="grid gap-6">
                        <div>
                            <label class="thread-label" for="file_path">Server file path</label>
                            <input id="file_path" type="text" name="file_path" placeholder="/data/leaks/raw_dump.txt"
                                   class="thread-input mt-2">
                            <p class="mt-2 text-sm thread-muted">Absolute path to the raw source file on this machine.</p>
                        </div>
                        
                        <div>
                            <label class="thread-label" for="uploaded_file">Or upload file</label>
                            <input id="uploaded_file" type="file" name="uploaded_file"
                                   class="thread-input mt-2 text-slate-400">
                        </div>

                        <div class="grid gap-6 md:grid-cols-2">
                            <div>
                                <label class="thread-label" for="display_name">Display name</label>
                                <input id="display_name" type="text" name="display_name" required placeholder="Regional Archive 2026"
                                       class="thread-input mt-2">
                            </div>
                            <div>
                                <label class="thread-label" for="leak_date">Leak date</label>
                                <input id="leak_date" type="date" name="leak_date" required
                                       class="thread-input mt-2">
                            </div>
                        </div>

                        <div>
                            <label class="thread-label" for="classification">Structural tag</label>
                            <select id="classification" name="classification" class="thread-select mt-2">
                                <option value="UNSTRUCTURED">UNSTRUCTURED / MIXED</option>
                                <option value="CSV">CSV / DELIMITED</option>
                                <option value="JSON">JSON / OBJECTS</option>
                                <option value="SQL">SQL DUMP / INSERTS</option>
                            </select>
                        </div>

                        <div>
                            <label class="thread-label" for="retention_policy">Retention class</label>
                            <select id="retention_policy" name="retention_policy" class="thread-select mt-2">
                                @foreach ($retentionPolicies as $value => $policy)
                                    <option value="{{ $value }}" @selected(old('retention_policy', 'breach') === $value)>
                                        {{ $policy['label'] }} - {{ $policy['retention'] }}
                                    </option>
                                @endforeach
                            </select>
                            <p class="mt-2 text-sm thread-muted">
                                Breach leaks are retained indefinitely. Stealer, ULP, Telegram, and scraped leaks expire after 3 months.
                            </p>
                        </div>
                    </div>

                    <div class="mt-8 grid gap-3 border-t border-slate-800/80 pt-6 md:grid-cols-[1fr_auto] md:items-center">
                        <div class="text-sm thread-muted">
                            Ingestion normalizes the source file, records metadata in MariaDB, and triggers qgrep indexing.
                        </div>
                        <button type="submit" class="thread-button md:w-auto">
                            Start ingest
                        </button>
                    </div>
                </form>

                <form action="{{ route('admin.users.store') }}" method="POST" class="thread-panel p-4 sm:p-6 lg:p-8">
                    @csrf

                    <div class="mb-6">
                        <p class="thread-kicker">Account create</p>
                        <h2 class="mt-1 text-2xl font-bold text-white">New account</h2>
                    </div>

                    <div class="grid gap-5">
                        <div>
                            <label class="thread-label" for="new_name">Name</label>
                            <input id="new_name" type="text" name="name" value="{{ old('name') }}" required
                                   class="thread-input mt-2">
                        </div>
                        <div>
                            <label class="thread-label" for="new_email">Email</label>
                            <input id="new_email" type="email" name="email" value="{{ old('email') }}" required
                                   class="thread-input mt-2">
                        </div>
                        <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-1 2xl:grid-cols-2">
                            <div>
                                <label class="thread-label" for="new_password">Password</label>
                                <input id="new_password" type="password" name="password" required
                                       class="thread-input mt-2">
                            </div>
                            <div>
                                <label class="thread-label" for="new_password_confirmation">Confirm password</label>
                                <input id="new_password_confirmation" type="password" name="password_confirmation" required
                                       class="thread-input mt-2">
                            </div>
                        </div>
                        <label class="flex items-center gap-3 text-sm text-slate-200">
                            <input type="checkbox" name="is_admin" value="1" @checked(old('is_admin'))
                                   class="h-4 w-4 rounded border-slate-700 bg-slate-950 text-teal-400">
                            Admin access
                        </label>
                    </div>

                    <div class="mt-8 border-t border-slate-800/80 pt-6">
                        <button type="submit" class="thread-button">
                            Create account
                        </button>
                    </div>
                </form>
            </div>

            <section class="mt-8">
                <div class="mb-4 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p class="thread-kicker">Leak inventory</p>
                        <h2 class="mt-1 text-2xl font-bold text-white">Loaded leaks</h2>
                    </div>
                    
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                        <form action="{{ route('admin.index') }}" method="GET" class="relative">
                            <input type="text" name="search" value="{{ $search }}" placeholder="Filter leaks..."
                                   class="thread-input min-w-[240px] pr-10">
                            <button type="submit" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-white">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                            </button>
                        </form>
                        @if($search)
                            <a href="{{ route('admin.index') }}" class="thread-link text-xs">Clear</a>
                        @endif
                        <div class="text-sm thread-muted">{{ count($capsules) }} leaks</div>
                    </div>
                </div>

                @if (count($capsules) === 0)
                    <div class="thread-panel p-5">
                        <p class="text-sm thread-muted">No leaks are loaded.</p>
                    </div>
                @else
                    <div class="grid gap-4">
                        @foreach ($capsules as $capsule)
                            @php($metadata = $capsule['metadata'])
                            <div class="thread-panel p-4 sm:p-5">
                                <div class="mb-4 flex flex-col gap-3 border-b border-slate-800/80 pb-4 lg:flex-row lg:items-start lg:justify-between">
                                    <div class="min-w-0">
                                        <p class="thread-kicker">Leak File</p>
                                        <h3 class="mt-1 truncate text-lg font-bold text-white">
                                            {{ $metadata['display_name'] ?? 'Unreadable metadata' }}
                                        </h3>
                                        <p class="mt-1 truncate text-sm thread-muted">{{ $capsule['filename'] }}</p>
                                    </div>
                                    <div class="flex flex-wrap gap-2 text-sm">
                                        <span class="rounded-full border border-slate-700/80 px-3 py-1 thread-muted">{{ $capsule['size'] }}</span>
                                        <span class="rounded-full border border-slate-700/80 px-3 py-1 thread-muted">Ingested {{ $capsule['modified_at'] }}</span>
                                    </div>
                                </div>

                                @if ($metadata)
                                    <div class="grid gap-4 text-sm md:grid-cols-2 xl:grid-cols-4">
                                        <div>
                                            <p class="thread-kicker">Rows</p>
                                            <p class="mt-1 text-slate-100">{{ number_format((int) ($metadata['total_lines'] ?? 0)) }}</p>
                                        </div>
                                        <div>
                                            <p class="thread-kicker">Leak date</p>
                                            <p class="mt-1 text-slate-100">{{ $metadata['leak_date'] ?? 'Unknown' }}</p>
                                        </div>
                                        <div>
                                            <p class="thread-kicker">Format</p>
                                            <p class="mt-1 text-slate-100">{{ $metadata['data_format'] ?? 'Unknown' }}</p>
                                        </div>
                                        <div>
                                            <p class="thread-kicker">Retention</p>
                                            <p class="mt-1 text-slate-100">{{ $metadata['retention_label'] ?? $metadata['retention_policy'] ?? 'Unknown' }}</p>
                                        </div>
                                        <div>
                                            <p class="thread-kicker">Expires</p>
                                            <p class="mt-1 text-slate-100">{{ ($metadata['retention_expires_at'] ?? null) ?: 'Never' }}</p>
                                        </div>
                                        <div>
                                            <p class="thread-kicker">Ingested</p>
                                            <p class="mt-1 text-slate-100">{{ $metadata['ingested_at'] ?? 'Unknown' }}</p>
                                        </div>
                                    </div>
                                @else
                                    <div class="thread-alert thread-alert-error">
                                        This leak file exists, but its metadata could not be found in MariaDB.
                                    </div>
                                @endif

                                <form action="{{ route('admin.capsules.destroy', ['capsule' => $capsule['id']]) }}" method="POST" class="mt-5 flex justify-end"
                                      onsubmit="return confirm('Delete this leak and its associated file?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="thread-danger">
                                        Delete leak
                                    </button>
                                </form>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>

            <section class="mt-8">
                <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p class="thread-kicker">Account directory</p>
                        <h2 class="mt-1 text-2xl font-bold text-white">Users</h2>
                    </div>
                    <div class="text-sm thread-muted">{{ $users->count() }} accounts</div>
                </div>

                <div class="grid gap-4">
                    @foreach ($users as $user)
                        <div class="thread-panel p-4 sm:p-5">
                            <div class="mb-4 flex flex-col gap-2 border-b border-slate-800/80 pb-4 sm:flex-row sm:items-center sm:justify-between">
                                <div class="min-w-0">
                                    <h3 class="truncate text-lg font-bold text-white">{{ $user->name }}</h3>
                                    <p class="truncate text-sm thread-muted">{{ $user->email }}</p>
                                </div>
                                <div class="flex flex-wrap gap-2 text-sm">
                                    @if ($user->is_admin)
                                        <span class="rounded-full border border-teal-400/30 bg-teal-400/10 px-3 py-1 text-teal-200">Admin</span>
                                    @else
                                        <span class="rounded-full border border-slate-700/80 px-3 py-1 thread-muted">User</span>
                                    @endif
                                    <span class="rounded-full border border-slate-700/80 px-3 py-1 thread-muted">ID {{ $user->id }}</span>
                                </div>
                            </div>

                            <form id="update-user-{{ $user->id }}" action="{{ route('admin.users.update', $user) }}" method="POST">
                                @csrf
                                @method('PUT')

                                <div class="grid gap-4 lg:grid-cols-2">
                                    <div>
                                        <label class="thread-label" for="name_{{ $user->id }}">Name</label>
                                        <input id="name_{{ $user->id }}" type="text" name="name" value="{{ $user->name }}" required
                                               class="thread-input mt-2">
                                    </div>
                                    <div>
                                        <label class="thread-label" for="email_{{ $user->id }}">Email</label>
                                        <input id="email_{{ $user->id }}" type="email" name="email" value="{{ $user->email }}" required
                                               class="thread-input mt-2">
                                    </div>
                                    <div>
                                        <label class="thread-label" for="password_{{ $user->id }}">New password</label>
                                        <input id="password_{{ $user->id }}" type="password" name="password"
                                               class="thread-input mt-2">
                                    </div>
                                    <div>
                                        <label class="thread-label" for="password_confirmation_{{ $user->id }}">Confirm password</label>
                                        <input id="password_confirmation_{{ $user->id }}" type="password" name="password_confirmation"
                                               class="thread-input mt-2">
                                    </div>
                                </div>

                                <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    @if (auth()->id() === $user->id)
                                        <label class="flex items-center gap-3 text-sm text-slate-200">
                                            <input type="hidden" name="is_admin" value="1">
                                            <input type="checkbox" checked disabled
                                                   class="h-4 w-4 rounded border-slate-700 bg-slate-950 text-teal-400 opacity-70">
                                            Admin access
                                        </label>
                                    @else
                                        <label class="flex items-center gap-3 text-sm text-slate-200">
                                            <input type="checkbox" name="is_admin" value="1" @checked($user->is_admin)
                                                   class="h-4 w-4 rounded border-slate-700 bg-slate-950 text-teal-400">
                                            Admin access
                                        </label>
                                    @endif
                                    <button type="submit" class="thread-link">
                                        Update account
                                    </button>
                                </div>
                            </form>

                            @if (auth()->id() === $user->id)
                                <div class="mt-3 flex justify-end text-sm thread-muted">
                                    Current session
                                </div>
                            @else
                                <form action="{{ route('admin.users.destroy', $user) }}" method="POST" class="mt-3 flex justify-end"
                                      onsubmit="return confirm('Delete this account?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="thread-danger">
                                        Delete account
                                    </button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        </main>
    </div>
</body>
</html>
