{{--
    FILE PURPOSE
    File   : resources/views/auth/dashboard-pending.blade.php
    Feature: Login / Auth
    Purpose: Placeholder page for a role whose dashboard is not ready.
--}}

<x-app-layout :title="$roleName . ' Dashboard'" active="dashboard">
    <section class="phase-notice">
        <h1>{{ $roleName }} sign-in is working</h1>
        <p>The AstraTrack dashboard pages will be connected during Phase 4.</p>
    </section>
</x-app-layout>
