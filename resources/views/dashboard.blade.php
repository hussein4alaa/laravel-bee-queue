<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>G4T Bee Queue Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .badge { @apply inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium; }
    </style>
</head>
<body class="bg-gray-950 text-gray-100 min-h-screen font-sans">

{{-- Header --}}
<header class="bg-gray-900 border-b border-gray-800 px-6 py-4 flex items-center justify-between">
    <div class="flex items-center gap-3">
        <img src="{{ asset('vendor/bee-queue/bee-logo.png') }}" alt="G4T Bee Queue" class="h-8 w-8 object-contain" />
        <h1 class="text-xl font-bold text-yellow-400 tracking-tight">G4T Bee Queue</h1>
        <span class="text-gray-500 text-sm">Dashboard</span>
    </div>
    <form method="GET" action="{{ route('bee-queue.dashboard') }}" class="flex items-center gap-2">
        <label class="text-gray-400 text-sm">Queue:</label>
        <input
            type="text"
            name="queue"
            value="{{ $queueName }}"
            class="bg-gray-800 border border-gray-700 rounded px-3 py-1.5 text-sm text-white focus:outline-none focus:border-yellow-500 w-36"
        />
        <input type="hidden" name="status" value="{{ $status }}" />
        <button class="bg-yellow-500 hover:bg-yellow-400 text-gray-900 font-semibold text-sm px-3 py-1.5 rounded transition">
            Switch
        </button>
    </form>
</header>

<div class="max-w-7xl mx-auto px-6 py-8 space-y-8">

    {{-- Flash message --}}
    @if(session('success'))
        <div class="bg-green-900/50 border border-green-700 text-green-300 px-4 py-3 rounded-lg text-sm">
            ✅ {{ session('success') }}
        </div>
    @endif

    {{-- Stats cards --}}
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
        @foreach([
            'waiting'   => ['color' => 'blue',   'icon' => '⏳'],
            'active'    => ['color' => 'yellow',  'icon' => '⚡'],
            'succeeded' => ['color' => 'green',   'icon' => '✅'],
            'failed'    => ['color' => 'red',     'icon' => '❌'],
            'delayed'   => ['color' => 'purple',  'icon' => '🕐'],
        ] as $s => $meta)
            @php $count = $stats[$s] ?? 0; @endphp
            <a href="{{ route('bee-queue.dashboard', ['queue' => $queueName, 'status' => $s]) }}"
               class="bg-gray-900 border rounded-xl p-4 flex flex-col gap-1 transition hover:scale-[1.02]
                      {{ $status === $s ? 'border-yellow-500 ring-1 ring-yellow-500/40' : 'border-gray-800 hover:border-gray-600' }}">
                <div class="flex items-center justify-between">
                    <span class="text-gray-400 text-xs uppercase tracking-wider">{{ $s }}</span>
                    <span>{{ $meta['icon'] }}</span>
                </div>
                <span class="text-3xl font-bold
                    {{ $s === 'failed' && $count > 0 ? 'text-red-400' : 'text-white' }}">
                    {{ number_format($count) }}
                </span>
            </a>
        @endforeach
    </div>

    {{-- Jobs table --}}
    <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-800 flex items-center justify-between">
            <h2 class="font-semibold text-gray-200 capitalize">
                {{ $status }} jobs
                <span class="ml-2 text-xs text-gray-500 font-normal">(showing up to 50)</span>
            </h2>
            <span class="text-xs text-gray-600">Queue: <span class="text-gray-400">{{ $queueName }}</span></span>
        </div>

        @if(empty($jobs))
            <div class="px-5 py-16 text-center text-gray-600">
                <div class="text-4xl mb-3" style="display: flex; justify-content: center;">
        <img src="{{ asset('vendor/bee-queue/bee-logo.png') }}" alt="G4T Bee Queue" class="h-8 w-8 object-contain" />
                </div>
                <p>No {{ $status }} jobs.</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-800/50 text-gray-400 text-xs uppercase tracking-wider">
                        <tr>
                            <th class="px-5 py-3 text-left w-16">ID</th>
                            <th class="px-5 py-3 text-left">Status</th>
                            <th class="px-5 py-3 text-left">Data</th>
                            <th class="px-5 py-3 text-left w-32">Timestamp</th>
                            <th class="px-5 py-3 text-left w-20">Progress</th>
                            <th class="px-5 py-3 text-right w-32">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        @foreach($jobs as $job)
                            @php
                                $jobStatus = $job['status'] ?? 'unknown';
                                $statusColor = match($jobStatus) {
                                    'waiting'   => 'bg-blue-900/50 text-blue-300',
                                    'active'    => 'bg-yellow-900/50 text-yellow-300',
                                    'succeeded' => 'bg-green-900/50 text-green-300',
                                    'failed'    => 'bg-red-900/50 text-red-300',
                                    'delayed'   => 'bg-purple-900/50 text-purple-300',
                                    default     => 'bg-gray-800 text-gray-400',
                                };
                                $ts = isset($job['options']['timestamp'])
                                    ? date('Y-m-d H:i:s', intval($job['options']['timestamp'] / 1000))
                                    : '—';
                                $progress = $job['progress'] ?? 0;
                            @endphp
                            <tr class="hover:bg-gray-800/40 transition-colors">
                                <td class="px-5 py-3 text-gray-500 font-mono">#{{ $job['id'] }}</td>
                                <td class="px-5 py-3">
                                    <span class="badge {{ $statusColor }}">{{ $jobStatus }}</span>
                                </td>
                                <td class="px-5 py-3 max-w-xs">
                                    <code class="text-xs text-gray-400 bg-gray-800 px-2 py-1 rounded block truncate">
                                        {{ json_encode($job['data'] ?? []) }}
                                    </code>
                                </td>
                                <td class="px-5 py-3 text-gray-500 text-xs">{{ $ts }}</td>
                                <td class="px-5 py-3">
                                    <div class="flex items-center gap-2">
                                        <div class="w-16 bg-gray-800 rounded-full h-1.5">
                                            <div class="bg-yellow-400 h-1.5 rounded-full" style="width: {{ $progress }}%"></div>
                                        </div>
                                        <span class="text-xs text-gray-500">{{ $progress }}%</span>
                                    </div>
                                </td>
                                <td class="px-5 py-3 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        {{-- Retry (only for failed) --}}
                                        @if($jobStatus === 'failed')
                                            <form method="POST"
                                                  action="{{ route('bee-queue.retry', ['queue' => $queueName, 'job' => $job['id']]) }}">
                                                @csrf
                                                <button class="text-xs bg-blue-800 hover:bg-blue-700 text-blue-200 px-2.5 py-1 rounded transition">
                                                    Retry
                                                </button>
                                            </form>
                                        @endif

                                        {{-- Delete --}}
                                        <form method="POST"
                                              action="{{ route('bee-queue.delete', ['queue' => $queueName, 'job' => $job['id']]) }}"
                                              onsubmit="return confirm('Delete job #{{ $job['id'] }}?')">
                                            @csrf
                                            @method('DELETE')
                                            <input type="hidden" name="status" value="{{ $status }}" />
                                            <button class="text-xs bg-red-900/60 hover:bg-red-800 text-red-300 px-2.5 py-1 rounded transition">
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

</div>

{{-- Auto-refresh every 5s --}}
<script>
    setTimeout(() => location.reload(), 5000);

    // Countdown
    let t = 5;
    const el = document.createElement('div');
    el.className = 'fixed bottom-4 right-4 text-xs text-gray-600';
    document.body.appendChild(el);
    setInterval(() => { el.textContent = `Auto-refresh in ${t--}s`; if (t < 0) t = 5; }, 1000);
</script>

</body>
</html>
