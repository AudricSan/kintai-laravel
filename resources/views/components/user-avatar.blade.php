@props(['user', 'size' => 'md'])

<flux:avatar :src="$user->avatarUrl()" :name="$user->name" :initials="$user->initials()" :size="$size" {{ $attributes }} />
