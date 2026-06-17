<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thin Thread Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
    @include('partials.thread-ui')
</head>
<body class="thread-shell">
    <main class="thread-page flex min-h-screen items-center justify-center px-4 py-8 sm:px-6">
        <div class="w-full max-w-md">
            <header class="mb-8 flex flex-col items-center text-center">
                <div class="thread-logo" aria-hidden="true">
                    <span></span>
                    <span></span>
                    <span></span>
                </div>
                <p class="thread-kicker mt-5">Leak database access</p>
                <h1 class="mt-2 text-3xl font-bold text-white sm:text-4xl">Thin Thread</h1>
                <p class="mt-2 text-sm thread-muted">Authenticated workspace</p>
            </header>

            @if ($errors->any())
                <div class="thread-alert thread-alert-error mb-5">
                    <ul class="list-disc pl-5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form action="/login" method="POST" class="thread-panel p-5 sm:p-7">
                @csrf

                <div class="grid gap-5">
                    <div>
                        <label class="thread-label" for="email">Email</label>
                        <input id="email" type="email" name="email" required autofocus placeholder="operator@thin-thread.local"
                               class="thread-input mt-2">
                    </div>

                    <div>
                        <label class="thread-label" for="password">Password</label>
                        <input id="password" type="password" name="password" required
                               class="thread-input mt-2">
                    </div>
                </div>

                <button type="submit" class="thread-button mt-7">
                    Sign in
                </button>
            </form>
        </div>
    </main>
</body>
</html>
