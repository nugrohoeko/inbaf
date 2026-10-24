<script setup>
import {ref, computed, nextTick, onMounted, onBeforeUnmount} from 'vue';
import {state, api, go, notify, brandLogo, toggleLanguage, toggleTheme} from '../store';
import Icon from './Icon.vue';

// Capture credentials before removing the query. Keep them out of reactive state,
// persistent storage, rendered markup, navigation URLs, and subsequent referrers.
const query = new URLSearchParams(location.search || state.query);
let credentials = {email:(query.get('email') || '').trim(), token:query.get('token') || ''};
history.replaceState(history.state, '', location.pathname);
state.query = '';
query.delete('email'); query.delete('token');

const copy = (en, id) => state.language === 'id' ? id : en;
const phase = ref('checking'), activation = ref(false), saving = ref(false), error = ref(''), errors = ref({}), heading = ref(null), formElement = ref(null);
const password = ref(''), confirmation = ref(''), showPassword = ref(false), showConfirmation = ref(false), accountEmail = ref('');
let disposed = false, inspectionRevision = 0;
const ready = computed(() => phase.value === 'ready');
const title = computed(() => ({
    checking:copy('Checking your secure link','Memeriksa tautan aman Anda'),
    ready:activation.value ? copy('Activate your account','Aktifkan akun Anda') : copy('Reset your password','Atur ulang password Anda'),
    invalid:copy('This link is invalid or expired','Tautan tidak valid atau kedaluwarsa'),
    retry:copy('We could not check this link','Tautan belum dapat diperiksa'),
    success:copy('Your password is ready','Password Anda sudah siap'),
}[phase.value] || copy('Set your password','Buat password Anda')));
const rules = computed(() => [
    {met:Array.from(password.value).length >= 8, label:copy('At least 8 characters','Minimal 8 karakter')},
    {met:/\p{Ll}/u.test(password.value) && /\p{Lu}/u.test(password.value), label:copy('Uppercase and lowercase letters','Huruf besar dan huruf kecil')},
    {met:/\p{N}/u.test(password.value), label:copy('A number','Angka')},
    {met:/[\p{Z}\p{S}\p{P}]/u.test(password.value), label:copy('A symbol','Simbol')},
    {met:new TextEncoder().encode(password.value).length <= 72, label:copy('No more than 72 bytes','Tidak lebih dari 72 byte')},
]);
const strong = computed(() => rules.value.every(rule => rule.met));
function fieldError(name) { const value = errors.value[name]; return Array.isArray(value) ? value.join(' ') : value || ''; }
function clearError(name) { delete errors.value[name]; }
function clearPasswords() { password.value = ''; confirmation.value = ''; showPassword.value = false; showConfirmation.value = false; }
function clearCredentials() { credentials.email = ''; credentials.token = ''; }
async function focusHeading() { await nextTick(); if (!disposed) heading.value?.focus({preventScroll:true}); }
async function focusInvalidField() { await nextTick(); if (!disposed) formElement.value?.querySelector('[aria-invalid="true"]')?.focus(); }
function tokenError(failure) {
    return Boolean(failure.data?.errors?.token || failure.data?.errors?.email || ['invalid_token','expired_token','token_expired'].includes(failure.data?.code) || [404,410].includes(failure.status));
}
async function inspect() {
    const revision = ++inspectionRevision;
    phase.value = 'checking'; error.value = ''; errors.value = {};
    if (!credentials.email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(credentials.email) || credentials.email.length > 254 || credentials.token.length < 32 || credentials.token.length > 255) {
        phase.value = 'invalid'; clearCredentials(); await focusHeading(); return;
    }
    try {
        const response = await api('/set-password/inspect', {method:'POST', body:{...credentials}});
        if (disposed || revision !== inspectionRevision) return;
        if (!response.valid) { phase.value = 'invalid'; clearCredentials(); }
        else { activation.value = Boolean(response.activation); accountEmail.value = credentials.email; phase.value = 'ready'; }
    } catch (failure) {
        if (disposed || revision !== inspectionRevision) return;
        if (tokenError(failure) || failure.status === 422) { phase.value = 'invalid'; clearCredentials(); }
        else { phase.value = 'retry'; error.value = failure.status === 429 ? copy('Too many attempts. Wait a minute, then try again.','Terlalu banyak percobaan. Tunggu satu menit, lalu coba lagi.') : copy('Check your connection and try again. Your password has not been changed.','Periksa koneksi Anda lalu coba lagi. Password Anda belum berubah.'); }
    }
    await focusHeading();
}
async function savePassword() {
    if (!ready.value || saving.value) return;
    errors.value = {}; error.value = '';
    if (!strong.value) errors.value.password = [copy('Use a password that meets all the requirements below.','Gunakan password yang memenuhi semua syarat di bawah.')];
    if (!confirmation.value || password.value !== confirmation.value) errors.value.password_confirmation = [copy('The passwords must match.','Konfirmasi password harus sama.')];
    if (Object.keys(errors.value).length) { error.value = copy('Check the highlighted fields.','Periksa kolom yang ditandai.'); await focusInvalidField(); return; }
    saving.value = true;
    try {
        await api('/set-password', {method:'POST', body:{...credentials, password:password.value, password_confirmation:confirmation.value}});
        if (disposed) return;
        if (String(state.user?.email || '').toLowerCase() === credentials.email.toLowerCase()) state.user = null;
        clearPasswords(); clearCredentials(); accountEmail.value = ''; phase.value = 'success';
        notify(copy('Password saved. Sign in with your new password.','Password tersimpan. Silakan masuk dengan password baru.'));
        await focusHeading();
    } catch (failure) {
        if (disposed) return;
        if (tokenError(failure)) { phase.value = 'invalid'; clearPasswords(); clearCredentials(); accountEmail.value = ''; await focusHeading(); }
        else {
            errors.value = failure.data?.errors || {};
            error.value = failure.status === 429 ? copy('Too many attempts. Wait a minute before trying again.','Terlalu banyak percobaan. Tunggu satu menit sebelum mencoba lagi.') : failure.status === 422 ? copy('Check the highlighted fields.','Periksa kolom yang ditandai.') : copy('The password could not be saved. Check your connection and try again.','Password belum dapat disimpan. Periksa koneksi Anda lalu coba lagi.');
            await focusInvalidField();
        }
    } finally { saving.value = false; }
}
onMounted(inspect);
onBeforeUnmount(() => { disposed = true; inspectionRevision++; clearPasswords(); clearCredentials(); });
</script>

<template>
<div class="password-page">
    <a class="sp-skip" href="#sp-main">{{copy('Skip to password setup','Langsung ke pengaturan password')}}</a>
    <header class="sp-header"><a class="sp-brand" href="/" :aria-label="copy('INBAF home','Beranda INBAF')" @click.prevent="go('/')"><img :src="brandLogo()" alt="INBAF"></a><div class="sp-preferences"><button type="button" class="sp-language" :aria-label="copy('Switch to Bahasa Indonesia','Ganti ke English')" @click="toggleLanguage"><Icon name="Globe2" :size="19"/><span>{{state.language==='id'?'EN':'ID'}}</span></button><button type="button" class="icon-button" :aria-label="state.dark?copy('Use light mode','Gunakan mode terang'):copy('Use dark mode','Gunakan mode gelap')" @click="toggleTheme"><Icon :name="state.dark?'Sun':'Moon'" :size="21"/></button></div></header>
    <main id="sp-main" class="sp-main" tabindex="-1">
        <section class="sp-card" aria-labelledby="sp-title" :aria-busy="phase==='checking'||saving">
            <span class="sp-mark" :class="{'sp-success':phase==='success','sp-invalid':phase==='invalid'}"><Icon :name="phase==='success'?'CircleCheck':phase==='invalid'?'ShieldX':phase==='checking'?'ShieldCheck':'KeyRound'" :size="30"/></span>
            <span class="sp-eyebrow">{{copy('Secure account access','Akses akun yang aman')}}</span>
            <h1 id="sp-title" ref="heading" tabindex="-1">{{title}}</h1>
            <div v-if="phase==='checking'" class="sp-checking" role="status"><span class="loader"></span><p>{{copy('Please wait while we verify your activation or reset link.','Mohon tunggu saat kami memverifikasi tautan aktivasi atau reset Anda.')}}</p></div>
            <template v-else-if="ready">
                <p class="sp-description">{{activation?copy('Choose your password to complete account activation.','Buat password Anda untuk menyelesaikan aktivasi akun.'):copy('Choose a strong new password for your INBAF account.','Buat password baru yang kuat untuk akun INBAF Anda.')}}</p>
                <div class="sp-account"><Icon name="User" :size="20"/><span><span>{{copy('Account email','Email akun')}}</span><strong>{{accountEmail}}</strong></span></div>
                <form ref="formElement" class="sp-form" novalidate @submit.prevent="savePassword">
                    <div v-if="error" class="sp-error" role="alert"><Icon name="CircleAlert" :size="20"/><p>{{error}}</p></div>
                    <div class="sp-field"><label for="sp-password">{{copy('New password','Password baru')}}</label><div class="sp-password-input"><input id="sp-password" v-model="password" name="password" :type="showPassword?'text':'password'" autocomplete="new-password" required :disabled="saving" :aria-invalid="Boolean(fieldError('password'))" :aria-describedby="fieldError('password')?'sp-password-error sp-password-rules':'sp-password-rules'" @input="clearError('password')"><button type="button" class="icon-button" :disabled="saving" :aria-label="showPassword?copy('Hide new password','Sembunyikan password baru'):copy('Show new password','Tampilkan password baru')" :aria-pressed="showPassword" @click="showPassword=!showPassword"><Icon :name="showPassword?'EyeOff':'Eye'" :size="21"/></button></div><p v-if="fieldError('password')" id="sp-password-error" class="sp-field-error">{{fieldError('password')}}</p></div>
                    <ul id="sp-password-rules" class="sp-rules" :aria-label="copy('Password requirements','Persyaratan password')"><li v-for="rule in rules" :key="rule.label" :class="{met:rule.met}"><Icon :name="rule.met?'CircleCheck':'Circle'" :size="17"/><span>{{rule.label}}</span></li></ul>
                    <div class="sp-field"><label for="sp-confirmation">{{copy('Confirm new password','Konfirmasi password baru')}}</label><div class="sp-password-input"><input id="sp-confirmation" v-model="confirmation" name="password_confirmation" :type="showConfirmation?'text':'password'" autocomplete="new-password" required :disabled="saving" :aria-invalid="Boolean(fieldError('password_confirmation'))" :aria-describedby="fieldError('password_confirmation')?'sp-confirmation-error':undefined" @input="clearError('password_confirmation')"><button type="button" class="icon-button" :disabled="saving" :aria-label="showConfirmation?copy('Hide password confirmation','Sembunyikan konfirmasi password'):copy('Show password confirmation','Tampilkan konfirmasi password')" :aria-pressed="showConfirmation" @click="showConfirmation=!showConfirmation"><Icon :name="showConfirmation?'EyeOff':'Eye'" :size="21"/></button></div><p v-if="fieldError('password_confirmation')" id="sp-confirmation-error" class="sp-field-error">{{fieldError('password_confirmation')}}</p></div>
                    <button type="submit" class="button sp-primary" :disabled="saving"><span v-if="saving" class="loader"></span><Icon v-else name="Check" :size="20"/>{{saving?copy('Saving password…','Menyimpan password…'):activation?copy('Activate account','Aktifkan akun'):copy('Save new password','Simpan password baru')}}</button>
                </form>
                <p class="sp-security"><Icon name="LockKeyhole" :size="18"/>{{copy('This link can be used once. Your password is never sent by email.','Tautan ini hanya dapat digunakan sekali. Password Anda tidak pernah dikirim melalui email.')}}</p>
            </template>
            <template v-else-if="phase==='success'"><p class="sp-description">{{activation?copy('Your account is activated and your password has been saved. You can now sign in to INBAF.','Akun Anda sudah aktif dan password tersimpan. Anda sekarang dapat masuk ke INBAF.'):copy('Your new password has been saved. Sign in to continue your INBAF journey.','Password baru Anda sudah tersimpan. Masuk untuk melanjutkan perjalanan INBAF Anda.')}}</p><a class="button sp-primary" href="/login" @click.prevent="go('/login')">{{copy('Go to sign in','Masuk ke akun')}}<Icon name="ArrowRight" :size="20"/></a></template>
            <template v-else-if="phase==='invalid'"><p class="sp-description">{{copy('The activation or reset link may have expired, already been used, or been replaced. Ask your administrator for a new link, then open it from your email.','Tautan aktivasi atau reset mungkin sudah kedaluwarsa, digunakan, atau diganti. Minta tautan baru kepada administrator, lalu buka melalui email Anda.')}}</p><div class="sp-help"><Icon name="CircleHelp" :size="21"/><p>{{copy('For your privacy, account and link details are not shown here.','Untuk menjaga privasi Anda, data akun dan tautan tidak ditampilkan di sini.')}}</p></div><a class="button secondary" href="/login" @click.prevent="go('/login')"><Icon name="ArrowLeft" :size="19"/>{{copy('Back to sign in','Kembali ke halaman masuk')}}</a></template>
            <template v-else-if="phase==='retry'"><p class="sp-description" role="alert">{{error}}</p><button type="button" class="button sp-primary" @click="inspect"><Icon name="RefreshCw" :size="20"/>{{copy('Try again','Coba lagi')}}</button><a class="sp-login-link" href="/login" @click.prevent="go('/login')">{{copy('Back to sign in','Kembali ke halaman masuk')}}</a></template>
        </section>
        <p class="sp-support">{{copy('Need a new activation or reset link? Contact your INBAF administrator.','Perlu tautan aktivasi atau reset baru? Hubungi administrator INBAF Anda.')}}</p>
    </main>
    <footer class="sp-footer"><Icon name="ShieldCheck" :size="18"/><span>{{state.settings.system_name||'INBAF'}} · {{copy('A confident next step in Indonesian.','Langkah percaya diri dalam bahasa Indonesia.')}}</span></footer>
</div>
</template>

<style scoped>
.password-page{min-height:100dvh;min-width:0;background:var(--bg);color:var(--text);font-size:1.125rem;line-height:1.75}.sp-header{display:flex;justify-content:space-between;align-items:center;gap:20px;padding:24px clamp(20px,5vw,80px);border-bottom:1px solid var(--border);background:var(--surface)}.sp-brand{display:flex;align-items:center;min-height:44px;max-width:50%}.sp-brand img{display:block;width:142px;height:44px;object-fit:contain;object-position:left center}.sp-preferences{display:flex;align-items:center;gap:10px}.sp-preferences .icon-button{width:44px;height:44px;border:1px solid var(--border);color:var(--text)}.sp-language{display:flex;align-items:center;justify-content:center;gap:8px;min-height:44px;padding:8px 12px;border:1px solid var(--border);border-radius:9px;font-size:1rem;font-weight:650;color:var(--text)}.sp-language:hover{background:var(--surface-alt)}.sp-main{max-width:664px;margin:auto;padding:52px 24px 40px;min-width:0}.sp-card{padding:40px;background:var(--surface);border:1px solid var(--border);border-radius:22px;box-shadow:var(--shadow);min-width:0}.sp-mark{display:flex;align-items:center;justify-content:center;width:64px;height:64px;border-radius:20px;background:var(--blue-soft);color:var(--color-accent);margin-bottom:24px}.sp-mark.sp-invalid{background:var(--surface-alt);color:var(--muted)}.sp-mark.sp-success{background:var(--blue-soft);color:var(--color-accent)}.sp-eyebrow{display:block;color:var(--muted);font-size:1rem;font-weight:650;letter-spacing:.03em;margin-bottom:10px}.sp-card h1{font-size:clamp(1.7rem,3vw,2.1rem);font-weight:750;letter-spacing:-.035em;line-height:1.4;overflow-wrap:anywhere;outline:none}.sp-card h1:focus-visible{outline:3px solid var(--color-accent);outline-offset:5px;border-radius:2px}.sp-description{color:var(--muted);margin-top:16px;font-size:1.125rem;line-height:1.8;overflow-wrap:anywhere}.sp-checking{display:flex;gap:16px;align-items:flex-start;color:var(--muted);margin-top:24px;font-size:1.125rem}.sp-checking .loader{flex-shrink:0;margin-top:6px}.sp-account{display:flex;align-items:center;gap:12px;border:1px solid var(--border);border-radius:12px;padding:14px 16px;margin:24px 0;background:var(--surface-alt)}.sp-account>svg{flex-shrink:0;color:var(--color-accent)}.sp-account>span{min-width:0}.sp-account>span>span{display:block;font-size:1rem;color:var(--muted)}.sp-account strong{display:block;overflow-wrap:anywhere;color:var(--heading);font-size:1.0625rem;font-weight:600;line-height:1.6}.sp-form{display:flex;flex-direction:column;gap:22px;min-width:0}.sp-field{min-width:0}.sp-field>label{display:block;font-size:1.125rem;font-weight:600;line-height:1.6;margin-bottom:9px}.sp-password-input{position:relative}.sp-password-input>input{display:block;min-width:0;width:100%;min-height:52px;padding:12px 56px 12px 14px!important;border:1px solid var(--border);border-radius:10px;background:var(--surface)!important;color:var(--text)!important;font-size:1.125rem;line-height:1.5}.sp-password-input>input[aria-invalid=true]{border-color:var(--color-danger)!important}.sp-password-input>.icon-button{position:absolute;right:4px;top:50%;transform:translateY(-50%);width:44px;height:44px;color:var(--muted)}.sp-rules{list-style:none;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px 16px;padding:18px;margin:0;background:var(--surface-alt);border:1px solid var(--border);border-radius:12px}.sp-rules li{display:flex;align-items:flex-start;gap:8px;font-size:1rem;line-height:1.7;color:var(--muted)}.sp-rules li>svg{flex-shrink:0;margin-top:5px}.sp-rules li.met{color:var(--color-accent)}.sp-field-error{color:var(--color-danger);font-size:1rem;line-height:1.7;margin-top:8px;overflow-wrap:anywhere}.sp-error{display:flex;gap:10px;align-items:flex-start;border:1px solid color-mix(in srgb,var(--color-danger) 45%,var(--border));border-radius:10px;padding:14px;background:color-mix(in srgb,var(--color-danger) 5%,var(--surface));color:var(--text);font-size:1rem;line-height:1.7}.sp-error>svg{flex-shrink:0;margin-top:4px;color:var(--color-danger)}.sp-error p{min-width:0;overflow-wrap:anywhere}.sp-card .button{width:100%;max-width:100%;min-height:52px;font-size:1.125rem;line-height:1.6;white-space:normal;padding:13px 18px}.sp-card>.button{margin-top:28px}.sp-primary{background:var(--color-primary)!important;color:#092522!important}.sp-primary:hover{background:var(--color-primary-hover)!important}.sp-security{display:flex;gap:10px;align-items:flex-start;margin-top:24px;padding-top:22px;border-top:1px solid var(--border);font-size:1rem;line-height:1.75;color:var(--muted)}.sp-security>svg{flex-shrink:0;margin-top:5px;color:var(--color-accent)}.sp-help{display:flex;gap:12px;align-items:flex-start;padding:16px;border:1px solid var(--border);border-radius:12px;margin-top:24px;background:var(--surface-alt);font-size:1rem;line-height:1.75;color:var(--muted)}.sp-help>svg{flex-shrink:0;margin-top:4px;color:var(--color-accent)}.sp-support{font-size:1rem;line-height:1.8;color:var(--muted);text-align:center;margin:24px 16px 0;overflow-wrap:anywhere}.sp-footer{display:flex;justify-content:center;align-items:flex-start;gap:9px;color:var(--muted);font-size:1rem;line-height:1.7;padding:20px 24px 28px;text-align:center}.sp-footer>svg{flex-shrink:0;color:var(--color-accent);margin-top:4px}.sp-login-link{display:flex;justify-content:center;align-items:center;min-height:48px;color:var(--color-accent);text-decoration:underline;text-underline-offset:4px;font-size:1.0625rem;margin-top:14px}.sp-skip{position:absolute;left:16px;top:-100px;background:var(--surface);border:2px solid var(--color-accent);padding:12px;z-index:20;border-radius:8px;max-width:calc(100% - 32px)}.sp-skip:focus{top:12px}.password-page :is(button,input,a):focus-visible{outline:3px solid var(--color-accent);outline-offset:3px}.password-page :is(p,button,a){overflow-wrap:anywhere}.password-page .loader{flex-shrink:0}.password-page :is(input,button){scroll-margin:32px}.sp-main:focus{outline:none}
@media(max-width:600px){.sp-header{padding:16px 20px;gap:12px}.sp-brand img{width:118px;height:40px}.sp-preferences{gap:8px}.sp-language{padding:8px;gap:6px}.sp-main{padding:28px 16px 24px}.sp-card{padding:28px 24px;border-radius:18px}.sp-rules{grid-template-columns:1fr;padding:16px;gap:10px}.sp-description{font-size:1.0625rem}.sp-mark{width:56px;height:56px;border-radius:17px;margin-bottom:20px}.sp-eyebrow{font-size:1rem}.sp-card h1{font-size:1.75rem}.sp-support{margin-inline:8px}.sp-footer{padding:16px 20px 24px;text-align:left}.sp-account{padding:14px;align-items:flex-start}.sp-account>svg{margin-top:5px}}
@media(max-width:360px){.sp-header{padding-inline:16px}.sp-brand img{width:104px}.sp-language{font-size:.9375rem}.sp-language>svg{display:none}.sp-card{padding:24px 20px}.sp-main{padding-inline:12px}.sp-card h1{font-size:1.6rem}.sp-card .button{padding-inline:12px}.sp-help{gap:10px;padding:14px}.sp-rules{padding:14px}.sp-account{gap:10px}.sp-footer{font-size:.9375rem}}
@media(prefers-reduced-motion:reduce){.password-page *{transition:none!important;animation:none!important}}
</style>
