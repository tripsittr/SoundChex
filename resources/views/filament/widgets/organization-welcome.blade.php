<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-xl font-semibold tracking-tight text-gray-950 dark:text-white">
                    Welcome{{ $userName ? ', ' . $userName : '' }}
                </h2>

                @if ($organizationName)
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        You're working in <span class="font-medium text-gray-700 dark:text-gray-200">{{ $organizationName }}</span>.
                    </p>
                @else
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Select an organization to get started.
                    </p>
                @endif
            </div>

            <div class="flex flex-wrap items-center gap-3">
                @if ($inviteUrl)
                    <x-filament::button :href="$inviteUrl" tag="a" icon="heroicon-o-user-plus">
                        Invite a member
                    </x-filament::button>
                @endif

                @if ($organizationUrl)
                    <x-filament::button :href="$organizationUrl" tag="a" color="gray" icon="heroicon-o-building-office-2">
                        Organization profile
                    </x-filament::button>
                @endif
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
