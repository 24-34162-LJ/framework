{{--
    FILE PURPOSE
    File   : resources/views/admin/users/index.blade.php
    Feature: Admin - User management
    Purpose: Admin user management page.
--}}

<x-app-layout title="User Management" active="users" legacy-dashboard :show-notifications="false">
    @push('styles')
        <link href="{{ asset('assets/css/admin.css') }}?v=9" rel="stylesheet">
        <link href="{{ asset('assets/css/user_management.css') }}?v=9" rel="stylesheet">
    @endpush
    @push('scripts')
        <script>window.UM = { formState: @json($formState), total: {{ $total }}, targetId: @json(request()->query('user_id')) };</script>
        <script src="{{ asset('assets/js/user_management.js') }}?v=2" defer></script>
    @endpush

    <div class="user-management-page">
        @if (session('success'))<div class="flash flash-success" role="status">{{ session('success') }}</div>@endif
        @if (session('error'))<div class="flash flash-error" role="alert">{{ session('error') }}</div>@endif
        @if (session('errors')?->getBag('default')->any())<div class="flash flash-error" role="alert">{{ session('errors')->getBag('default')->first() }}</div>@endif

        <section class="stats" aria-label="User totals">
            <article class="card stat"><div class="stat-head"><span>Total Users</span></div><strong>{{ number_format($total) }}</strong><small>{{ $total ? $activeCount.' active · '.$inactiveCount.' inactive' : 'No accounts yet' }}</small></article>
            <article class="card stat"><div class="stat-head"><span>Administrators</span></div><strong>{{ number_format($adminCount) }}</strong>@unless ($total)<small>No accounts yet</small>@endunless</article>
            <article class="card stat"><div class="stat-head"><span>Staff Accounts</span></div><strong>{{ number_format($staffCount) }}</strong>@unless ($total)<small>No accounts yet</small>@endunless</article>
            <article class="card stat"><div class="stat-head"><span>Client Accounts</span></div><strong>{{ number_format($clientCount) }}</strong>@unless ($total)<small>No accounts yet</small>@endunless</article>
        </section>

        

        <section class="card users-card">
            <div class="filters">
                <label class="f-search"><span>Search users</span><div class="input-icon"><span aria-hidden="true">⌕</span><input type="text" id="searchInput" placeholder="Search by name, email, or user ID." autocomplete="off"></div></label>
                <label class="f-select"><span>Role</span><select id="roleFilter"><option value="">All Roles</option><option value="admin">Admin</option><option value="staff">Staff</option><option value="client">Client</option></select></label>
                <label class="f-select f-wide"><span>Account Status</span><select id="statusFilter"><option value="">All</option><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
                <button type="button" class="btn-add" id="addUserBtn">+ Add User</button>
            </div>

            <div class="table-wrap">
                <table class="users-table" id="usersTable">
                    <thead><tr><th>User ID</th><th>Full Name</th><th>Email Address</th><th>Role</th><th>Account Status</th><th>Date Created</th><th>Actions</th></tr></thead>
                    <tbody>
                        @foreach ($users as $user)
                            @php($isSelf = (int) $user->user_id === (int) auth()->user()->user_id)
                            @php($code = 'USR-'.str_pad((string) $user->user_id, 4, '0', STR_PAD_LEFT))
                            @php($created = $user->created_at ? \Illuminate\Support\Carbon::parse($user->created_at)->format('M d, Y') : '—')
                            <tr data-id="{{ $user->user_id }}" data-code="{{ $code }}" data-name="{{ $user->full_name }}" data-email="{{ $user->email }}" data-role="{{ $user->role }}" data-status="{{ $user->status }}" data-created="{{ $created }}" data-client-id="{{ $user->client_id ?? 0 }}" data-phone="{{ $user->phone ? '0'.substr($user->phone, 2) : '' }}" data-client-name="{{ $user->client?->client_name ?? '' }}" data-self="{{ $isSelf ? 1 : 0 }}">
                                <td class="uid" data-label="User ID">{{ $code }}</td>
                                <td data-label="Full Name"><span>{{ $user->full_name }}@if ($user->client)<small class="sub">{{ $user->client->client_name }}</small>@endif</span></td>
                                <td class="muted-cell" data-label="Email Address">{{ $user->email }}</td>
                                <td data-label="Role"><span class="pill role-{{ $user->role }}">{{ ucfirst($user->role) }}</span></td>
                                <td data-label="Account Status"><span class="pill status-{{ $user->status }}">{{ ucfirst($user->status) }}</span></td>
                                <td class="muted-cell" data-label="Date Created">{{ $created }}</td>
                                <td data-label="Actions"><div class="row-actions">
                                    <button type="button" class="act act-view" data-do="view">View</button>
                                    <button type="button" class="act" data-do="edit">Edit</button>
                                    <button type="button" class="act act-toggle" data-do="status" @disabled($isSelf)>{{ $user->status === 'active' ? 'Deactivate' : 'Activate' }}</button>
                                    <button type="button" class="act act-del" data-do="delete" aria-label="Delete account" @disabled($isSelf) title="Delete account"><span aria-hidden="true">⌫</span></button>
                                </div></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if ($total === 0)
                    <div class="empty-state"><span class="empty-ico" aria-hidden="true">♙</span><h3>No user accounts yet</h3><p>Add your first user to start assigning roles and managing access to AstraTrack.</p><button type="button" class="btn-add js-add">+ Add User</button><small>Choose Admin, Staff, or Client for each new account.</small></div>
                @endif
                <div class="empty-state" id="emptyFilter" hidden><span class="empty-ico" aria-hidden="true">⌕</span><h3>No users match your filters</h3><p>Try a different name, email, or user ID, or clear your filters to see all {{ $total }} users.</p><button type="button" class="act" id="clearFilters">Clear filters</button><small>Roles: All Roles, Admin, Staff, Client · Status: All, Active, Inactive</small></div>
            </div>
            <div class="table-foot"><span class="muted" id="countText">{{ $total ? 'Showing 1–'.$total.' of '.$total.' users' : '0 users' }}</span><div class="pager"><button type="button" disabled aria-label="Previous">‹</button><button type="button" class="current">1</button><button type="button" disabled aria-label="Next">›</button></div></div>
        </section>
    </div>

    <dialog id="formDialog" class="dialog">
        <form method="post" action="{{ route('admin.users.save') }}" id="userForm" novalidate>
            @csrf
            <div class="dlg-head"><div><h2 id="formTitle">Add User</h2><p class="dlg-sub" id="formSub"></p></div><button type="button" class="x" data-close aria-label="Close">×</button></div>
            <p class="req-note" id="reqNote"></p><div class="form-alert" id="formAlert" hidden><span aria-hidden="true">!</span><span id="formAlertText"></span></div>
            <input type="hidden" name="action" id="fAction" value="add"><input type="hidden" name="user_id" id="fId">
            <div class="field" data-field="full_name"><label for="fName">Full Name *</label><input type="text" name="full_name" id="fName" placeholder="Enter full name" maxlength="100"><p class="hint" data-hint="Use the name displayed on the user account."></p></div>
            <div class="field" data-field="email"><label for="fEmail">Email Address *</label><input type="email" name="email" id="fEmail" placeholder="name@company.example" maxlength="120"><p class="hint" data-hint="Use a unique email address for this account."></p></div>
            <div class="field" data-field="password"><label for="fPassword" id="pwLabel">Password *</label><div class="pw-wrap"><input type="password" name="password" id="fPassword" placeholder="Enter password" maxlength="72" autocomplete="new-password"><button type="button" class="pw-toggle" id="pwToggle">Show</button></div><p class="hint" data-hint="Use at least 8 characters."></p></div>
            <div class="two">
                <div class="field" data-field="role"><label for="fRole">Role *</label><select name="role" id="fRole"><option value="">Select role</option><option value="admin">Admin</option><option value="staff">Staff</option><option value="client">Client</option></select><p class="hint" data-hint="Available roles: Admin, Staff, Client."></p></div>
                <div class="field" data-field="status"><label for="fStatus">Account Status *</label><select name="status" id="fStatus"><option value="active">Active</option><option value="inactive">Inactive</option></select><p class="hint" data-hint="Options: Active, Inactive."></p></div>
            </div>
            <div class="field" data-field="client_id" id="clientRow" hidden><label for="fClient">Client Company *</label><select name="client_id" id="fClient"><option value="">Select client company</option>@foreach ($clients as $client)<option value="{{ $client->client_id }}">{{ $client->client_name }}</option>@endforeach</select><p class="hint" data-hint="The company this client account belongs to."></p></div>
            <div class="field" data-field="phone"><label for="fPhone">Mobile Number</label><input type="tel" name="phone" id="fPhone" placeholder="09171234567" maxlength="20" inputmode="tel" autocomplete="off"><p class="hint" data-hint="Optional. Used for SMS updates to client users. Blank uses the company's mobile number."></p></div>
            <div class="note-box">Only Admin users may assign roles and manage status. Deactivating an account disables access but retains shipment, billing, and transaction history.</div>
            <div class="dlg-foot"><button type="button" class="act" data-close>Cancel</button><button type="submit" class="btn-add">Save User</button></div>
        </form>
    </dialog>

    <dialog id="viewDialog" class="dialog dialog-md">
        <div class="dlg-head"><div><h2>User Details</h2><p class="dlg-sub">Review identity, role, and current account access.</p></div><button type="button" class="x" data-close aria-label="Close">×</button></div>
        <div class="who"><span class="who-avatar" id="vAvatar"></span><div><h3 id="vName"></h3><p id="vEmail"></p><div class="pills" id="vPills"></div></div></div>
        <dl class="kv"><div><dt>User ID</dt><dd id="vCode"></dd></div><div><dt>Date Created</dt><dd id="vCreated"></dd></div><div><dt>Role</dt><dd id="vRole"></dd></div><div><dt>Account Status</dt><dd id="vStatus"></dd></div></dl>
        <div class="acct-box"><h4>Account management</h4><p>Admins can edit details, assign roles, or deactivate access. Deactivation retains shipment, billing, and transaction history.</p><div class="acct-actions"><button type="button" class="btn-add" id="vEdit">Edit User</button><button type="button" class="act act-lg" id="vToggle">Deactivate</button><button type="button" class="act act-lg act-danger" id="vDelete">Delete</button></div></div>
        <div class="dlg-foot"><button type="button" class="act" data-close>Close</button></div>
    </dialog>

    <dialog id="confirmDialog" class="dialog dialog-sm">
        <form method="post" action="{{ route('admin.users.save') }}" id="confirmForm">
            @csrf
            <div class="dlg-head"><div><h2 id="cTitle"></h2><p class="dlg-sub" id="cSub"></p></div><button type="button" class="x" data-close aria-label="Close">×</button></div>
            <input type="hidden" name="action" id="cAction"><input type="hidden" name="user_id" id="cId">
            <div class="user-card"><strong id="cName"></strong><p id="cMeta"></p><div class="pills" id="cPills"></div></div>
            <div class="alert-box" id="cAlert"><span aria-hidden="true">!</span><span id="cAlertText"></span></div><p class="c-body" id="cBody"></p>
            <div class="field" id="cConfirmRow" hidden><label for="cConfirm">Type <b>DELETE</b> to confirm</label><input type="text" name="confirm_text" id="cConfirm" autocomplete="off" placeholder="DELETE"></div>
            <div class="dlg-foot"><button type="button" class="act" data-close>Cancel</button><button type="submit" class="btn-add btn-danger" id="cSubmit"></button></div>
        </form>
    </dialog>
</x-app-layout>
