<x-app-layout title="My Profile" active="profile" profile-header>
    @push('styles')
        <link href="{{ asset('assets/css/client_dashbroad.css') }}?v=12" rel="stylesheet">
        <link href="{{ asset('assets/css/client_profile.css') }}?v=10" rel="stylesheet">
    @endpush

    <div class="profile-content client-profile-content">
        @foreach ([['Account details', $accountFields], ['Company details', $companyFields]] as [$section, $fields])
            <section class="profile-card" aria-labelledby="{{ \Illuminate\Support\Str::slug($section) }}-heading">
                <h2 id="{{ \Illuminate\Support\Str::slug($section) }}-heading">{{ $section }}</h2>
                <div class="profile-grid">
                    @foreach ($fields as $label => $value)
                        <div class="profile-field"><span>{{ $label }}</span><strong>{{ filled($value) ? $value : '—' }}</strong></div>
                    @endforeach
                </div>
            </section>
        @endforeach

        <section class="profile-card" aria-labelledby="sms-updates-heading">
            <div>
                <h2 id="sms-updates-heading">SMS updates</h2>
                <p class="profile-note">We text you when your shipment status changes, a document is requested or rejected, and when a shipment request is accepted or declined. Emails are sent to {{ auth()->user()->email }}.</p>
            </div>
            @if (session('success') === 'Contact settings saved.')
                <p class="profile-alert profile-alert-ok" role="status">Contact settings saved.</p>
            @endif
            <form method="post" action="{{ route('client.profile.contact') }}" class="profile-form" novalidate>
                @csrf
                <div class="profile-form-field">
                    <label for="profilePhone">Mobile number</label>
                    <input id="profilePhone" name="phone" type="tel" inputmode="tel" autocomplete="tel" maxlength="20" placeholder="09171234567"
                        value="{{ old('phone', $contact['phone'] ? '0'.substr($contact['phone'], 2) : '') }}"
                        @error('phone') aria-invalid="true" aria-describedby="profilePhoneError" @enderror>
                    @error('phone')
                        <small id="profilePhoneError" class="profile-error">{{ $message }}</small>
                    @else
                        <small>Leave blank to use your company's mobile number.</small>
                    @enderror
                </div>
                <label class="profile-check">
                    <input type="checkbox" name="sms_enabled" value="1" @checked(old('sms_enabled', $errors->any() ? null : $contact['sms_enabled']))>
                    <span>Send me SMS updates</span>
                </label>
                <div><button type="submit" class="profile-save">Save</button></div>
            </form>
        </section>
    </div>
</x-app-layout>
