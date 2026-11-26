@once
<style>
/* TPZ Enterprise Authentication — scoped to guest auth pages. No auth logic changes. */
body:has(.tpz-auth-hero) { background:#f0f7ff; }
body:has(.tpz-auth-hero) .fi-simple-layout {
    min-height:100dvh; padding:2rem 3vw 2rem 53%; display:flex; align-items:center;
    background:radial-gradient(circle at 90% 10%,#dcecff 0,transparent 34%), #f3f8ff;
}
body:has(.tpz-auth-hero) .fi-simple-main { width:100%; max-width:43rem; margin-inline:auto; }
body:has(.tpz-auth-hero) .fi-simple-page { width:100%; }
body:has(.tpz-auth-hero) .fi-simple-page-content {
    background:#fff; border:1px solid #e3eaf5; border-radius:1.8rem;
    box-shadow:0 22px 65px rgba(12,40,82,.11); padding:clamp(1.65rem,3.5vw,3.1rem);
    color:#102a52;
}
.tpz-auth-hero { box-sizing:border-box; position:fixed; inset:0 auto 0 0; z-index:2; width:51.5%;
    display:flex; flex-direction:column; overflow:hidden; padding:clamp(2rem,5vw,5.5rem) clamp(2rem,5vw,5rem);
    background:linear-gradient(155deg,#082a55 0%,#061e42 58%,#0b3c79 100%);
    color:#fff; border-radius:0 2rem 2rem 0;
}
.tpz-auth-hero::before { content:''; position:absolute; width:36rem;height:36rem; border:4rem solid rgba(30,154,255,.18); transform:rotate(40deg); top:-28rem;left:-12rem; border-radius:6rem; pointer-events:none; }
.tpz-auth-hero::after { content:''; position:absolute; width:30rem;height:30rem; border:3rem solid rgba(32,149,244,.15); transform:rotate(40deg); bottom:-25rem;left:-14rem;border-radius:4rem; pointer-events:none; }
.tpz-auth-hero > * { position:relative; z-index:1; }
.tpz-auth-hero-brand { display:flex; gap:1.1rem; align-items:center; }
.tpz-auth-monogram { font-size:clamp(2.7rem,5vw,4.5rem);font-style:italic;letter-spacing:-.09em;font-weight:900;line-height:1; color:white; }
.tpz-auth-monogram:after { content:''; display:inline-block; width:.5em; height:.15em; border-radius:100%; background:#26a6ff; transform:rotate(-35deg) translate(-.7em,-1.4em); }
.tpz-auth-hero-line { width:2px; height:4.1rem; background:rgba(255,255,255,.6); }
.tpz-auth-hero-company { font-size:clamp(1.1rem,2vw,2rem); font-weight:800;letter-spacing:.025em;line-height:1.12; }
.tpz-auth-hero-company span { display:block; color:#32b2ff;font-size:1.25em; }
.tpz-auth-hero-eyebrow { margin-top:1.7rem; font-size:.73rem;letter-spacing:.23em;font-weight:700;color:#d5eaff; }
.tpz-auth-hero-message { margin-top:clamp(2.25rem,8vh,6rem); max-width:36rem; }
.tpz-auth-hero-message h2 { font-weight:800; font-size:clamp(2rem,3.6vw,3.7rem);letter-spacing:-.04em;line-height:1.17; }
.tpz-auth-hero-message h2 span { color:#76c3ff; }
.tpz-auth-hero-message p { margin-top:1.1rem; color:#d5e7fb;font-size:clamp(.93rem,1.4vw,1.15rem);line-height:1.6; }
.tpz-auth-modules { display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:1rem .4rem;margin-top:clamp(2rem,6vh,4rem);max-width:36rem; }
.tpz-auth-module { min-width:0;display:flex;flex-direction:column;align-items:center;gap:.65rem;text-align:center;padding:.6rem .35rem; border-right:1px solid rgba(255,255,255,.2);color:#e9f4ff; font-size:.76rem;line-height:1.35; }
.tpz-auth-module:nth-child(4n) { border-right:none; }
.tpz-auth-module svg { width:1.7rem;height:1.7rem;stroke-width:1.6;color:#f6fbff; }
.tpz-auth-hero-bottom { margin-top:auto;padding-top:1.5rem;font-size:.75rem;color:#a8c9e8; }
.tpz-auth-verify-art { display:grid; place-items:center; margin:clamp(2rem,6vh,5rem) auto 0; width:min(19rem,65%);aspect-ratio:1.3;position:relative; }
.tpz-auth-verify-art::before { content:''; position:absolute; inset:2%; border:1px solid rgba(98,197,255,.45); border-radius:50%;background:radial-gradient(circle,rgba(20,144,247,.32),transparent 72%); box-shadow:0 0 0 2rem rgba(18,129,223,.06); }
.tpz-auth-verify-art svg { position:relative;width:52%;height:52%;color:#d9f2ff; filter:drop-shadow(0 0 18px #169dff);stroke-width:1.25; }
.tpz-auth-form-heading { text-align:center; margin:1.3rem 0 1.8rem; }
.tpz-auth-form-heading h2 { color:#092858;font-size:clamp(1.6rem,2.2vw,2.2rem);font-weight:850;letter-spacing:-.04em; }
.tpz-auth-form-heading p { color:#667b96; font-size:.9rem;margin-top:.4rem;line-height:1.5; }
.tpz-auth-form-heading .tpz-auth-email {display:block;color:#123b72;font-weight:700;overflow-wrap:anywhere;margin-top:.15rem;}
.tpz-auth-form-brand {display:flex;justify-content:center;align-items:center;gap:.65rem;margin-bottom:.35rem;text-align:center;}
.tpz-auth-form-brand .fi-simple-header { margin:0;display:flex;flex-direction:column;align-items:center;gap:.25rem; }
.tpz-auth-form-brand .fi-simple-header-heading {font-size:1.12rem!important;font-weight:800;color:#0c2d5f!important;}
.tpz-auth-form-brand .fi-simple-header-subheading {display:none;}
.tpz-auth-form-brand img {max-width:12rem;max-height:4rem;object-fit:contain;}
.tpz-auth-form-brand .tpz-auth-brand-fallback {font-size:2.4rem;font-weight:900;font-style:italic;letter-spacing:-.08em;color:#0c2d5f;}
body:has(.tpz-auth-hero) .fi-fo-field-wrp-label {font-weight:700;color:#15375e;}
body:has(.tpz-auth-hero) .fi-input-wrp {border:1px solid #d7e1ef;box-shadow:none;border-radius:.75rem; min-height:3.25rem;}
body:has(.tpz-auth-hero) .fi-input-wrp:focus-within {border-color:#2296ef;box-shadow:0 0 0 3px rgba(34,150,239,.12);}
body:has(.tpz-auth-hero) input {font-size:.93rem;}
body:has(.tpz-auth-hero) .tpz-auth-submit,body:has(.tpz-auth-hero) .fi-form-actions .fi-btn {background:linear-gradient(100deg,#082b5f,#093f86);color:white;border-radius:.8rem;min-height:3.2rem;font-size:1rem;font-weight:700;box-shadow:0 6px 12px rgba(7,45,94,.13);}
body:has(.tpz-auth-hero) .fi-form-actions {width:100%;}
body:has(.tpz-auth-hero) .fi-form-actions .fi-btn {width:100%;justify-content:center;}
.tpz-auth-otp-form {display:flex;flex-direction:column;gap:1.2rem;}
.tpz-auth-otp-form .fi-otp-input-ctn,.tpz-auth-otp-form .fi-one-time-code-input {justify-content:center;}
.tpz-auth-otp-form input[data-slot], .tpz-auth-otp-form .fi-one-time-code-input input {font-size:1.5rem!important;text-align:center;}
.tpz-auth-link {text-align:center;color:#1556aa;font-weight:650;font-size:.9rem;margin-top:1.2rem;}
.tpz-auth-link a {color:#1556aa;text-decoration:none;}
.tpz-auth-link a:hover {text-decoration:underline;}
.tpz-auth-otp-note {text-align:center;color:#6a7d92;font-size:.83rem;}
.tpz-auth-test-label {position:absolute;top:1rem;right:1rem;font-size:.7rem;border-radius:999px;border:1px solid rgba(255,255,255,.55);padding:.25rem .7rem;color:#fff;}
@media(max-width:900px) {
    body:has(.tpz-auth-hero) .fi-simple-layout {padding:2rem 1rem 3rem;display:flex;align-items:center;flex-direction:column;}
    body:has(.tpz-auth-hero) .fi-simple-main {width:100%;max-width:36rem;}
    .tpz-auth-hero {position:relative;inset:auto;width:100%;max-width:36rem;border-radius:1.4rem;margin-bottom:1rem;padding:1.5rem 1.6rem;min-height:auto;}
    .tpz-auth-hero-message,.tpz-auth-modules,.tpz-auth-verify-art,.tpz-auth-hero-bottom {display:none;}
    .tpz-auth-hero-brand {gap:.75rem;}
    .tpz-auth-hero-line {height:2.5rem;}
    .tpz-auth-monogram {font-size:2.1rem;}
    .tpz-auth-hero-company {font-size:1rem;}
    .tpz-auth-hero-eyebrow {margin-top:.7rem;font-size:.58rem;}
    .tpz-auth-test-label {top:.5rem;right:.7rem;}
    body:has(.tpz-auth-hero) .fi-simple-page-content {border-radius:1.2rem;padding:1.6rem 1.4rem;}
}
@media(prefers-reduced-motion:reduce) { .tpz-auth-hero * {scroll-behavior:auto!important;transition:none!important;} }
</style>
@endonce