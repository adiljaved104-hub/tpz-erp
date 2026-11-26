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

/* Visual alignment with approved login + OTP references (desktop and mobile). */
body:has(.tpz-auth-hero) {font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif!important;}
body:has(.tpz-auth-hero) .fi-simple-layout {padding:2rem 3.5vw 2rem 53%; align-items:center;}
body:has(.tpz-auth-hero) .fi-simple-main {position:relative;width:100%;max-width:40rem;background:#fff;border-radius:2rem;padding:clamp(2rem,4vw,3.8rem);border:1px solid #e4edf7;box-shadow:0 16px 55px rgba(11,47,91,.13);overflow:visible;}
body:has(.tpz-auth-hero) .fi-simple-page,body:has(.tpz-auth-hero) .fi-simple-page-content {width:100%;max-width:none;background:transparent!important;border:0!important;border-radius:0!important;box-shadow:none!important;padding:0!important;margin:0!important;}
body:has(.tpz-auth-hero) .fi-simple-main > .fi-simple-header:not([data-auth-branding]),
body:has(.tpz-auth-hero) .fi-simple-page > .fi-simple-header:not([data-auth-branding]),
body:has(.tpz-auth-hero) .fi-simple-page-content > .fi-simple-header:not([data-auth-branding]) {display:none!important;}
.tpz-auth-identity {display:flex;align-items:center;justify-content:center;flex-direction:column;text-align:center;gap:.25rem;margin-bottom:1.6rem;}
.tpz-auth-identity-logo {display:block;max-width:12rem;max-height:4.8rem;object-fit:contain;margin:0 auto .3rem;}
.tpz-auth-identity-monogram {color:#082b60;font-size:clamp(3.1rem,5vw,4.4rem);line-height:1;font-weight:950;letter-spacing:-.12em;font-style:italic;position:relative;padding-right:.2rem;}
.tpz-auth-identity-monogram span {display:block;position:absolute;top:-.12em;right:-.09em;width:1.15em;height:.48em;border-top:.1em solid #159cf3;border-right:.08em solid #159cf3;border-radius:80% 90% 0 0;transform:rotate(-15deg);}
.tpz-auth-identity-name {color:#0c2c5c!important;font-size:clamp(1.02rem,1.4vw,1.25rem)!important;line-height:1.25!important;font-weight:850!important;letter-spacing:-.025em;}
.tpz-auth-form-brand {margin:0!important;}
.tpz-auth-form-heading {margin:1.4rem 0 2rem;}
.tpz-auth-form-heading h2 {font-size:clamp(1.8rem,2.6vw,2.5rem);font-weight:850;color:#09285b;letter-spacing:-.045em;}
.tpz-auth-form-heading p {font-size:1rem;color:#71829b;}
body:has(.tpz-auth-hero) .fi-simple-main .fi-fo-field-wrp-label {font-size:.94rem;font-weight:750;color:#0c2b58;}
body:has(.tpz-auth-hero) .fi-simple-main .fi-input-wrp {min-height:3.6rem;border:1px solid #cfdceb!important;border-radius:.85rem!important;background:#f9fbfe;box-shadow:none!important;}
body:has(.tpz-auth-hero) .fi-simple-main .fi-input-wrp input {color:#0c2b58!important;font-size:1rem;}
body:has(.tpz-auth-hero) .fi-simple-main .fi-form-actions .fi-btn,
body:has(.tpz-auth-hero) .fi-simple-main .fi-btn.tpz-auth-submit {background:linear-gradient(100deg,#08295c,#0a3572)!important;background-color:#092d63!important;color:#fff!important;border-color:#092d63!important;min-height:3.5rem!important;font-size:1rem!important;font-weight:750!important;border-radius:.82rem!important;}
body:has(.tpz-auth-hero) .fi-simple-main .fi-form-actions .fi-btn:hover,
body:has(.tpz-auth-hero) .fi-simple-main .fi-btn.tpz-auth-submit:hover {background:#0d448e!important;}
body:has(.tpz-auth-hero) .fi-simple-main .fi-btn.tpz-auth-submit * {color:#fff!important;}
body:has(.tpz-auth-hero) .fi-simple-main .fi-btn:not(.tpz-auth-submit):not(.fi-form-actions .fi-btn) {font-weight:650;}
.tpz-auth-hero {background:linear-gradient(180deg,rgba(5,32,70,.18),rgba(5,27,61,.15)),linear-gradient(135deg,#0c3569 0%,#061e43 76%,#062a58 100%);padding:clamp(2.5rem,5vw,5.1rem) clamp(2.6rem,5vw,5.5rem);}
.tpz-auth-hero:before {border-color:rgba(24,153,247,.26);}
.tpz-auth-hero:after {width:100%;height:35%;left:0;bottom:0;top:auto;transform:none;border:0;border-radius:0;background:linear-gradient(to bottom,#0c2f60 1%,rgba(6,33,71,.55) 28%,rgba(4,24,52,.5) 92%),url('/branding/tpz-erp-building-background.svg') center bottom / cover no-repeat;opacity:.58;}
.tpz-auth-hero-message {margin-top:clamp(2rem,7vh,5rem);max-width:43rem;}
.tpz-auth-hero-message h2 {font-size:clamp(2.25rem,3.25vw,3.45rem);letter-spacing:-.05em;line-height:1.12;}
.tpz-auth-hero-message p {max-width:37rem;font-size:1.03rem;}
.tpz-auth-modules {margin-top:clamp(2rem,5vh,3rem);gap:1rem 0;max-width:38rem;}
.tpz-auth-module {font-size:.88rem;font-weight:500;padding:.45rem .4rem;}
.tpz-auth-module svg {width:2rem;height:2rem;}
.tpz-auth-hero-bottom {z-index:3;opacity:.85;}
.tpz-auth-verify-art {width:min(21rem,75%);margin-top:clamp(2rem,5vh,4rem);}
.tpz-auth-verify-art:after {content:"OTP  * * * * * *";position:absolute;right:-.6rem;bottom:20%;padding:.55rem 1rem;border:2px solid #8dd5ff;border-radius:.5rem;background:rgba(13,86,155,.86);box-shadow:0 5px 30px rgba(10,110,210,.28);font-size:1.1rem;font-weight:800;letter-spacing:.04em;}
.tpz-auth-otp-form .fi-otp-input-ctn, .tpz-auth-otp-form .fi-one-time-code-input {justify-content:space-between;}
.tpz-auth-otp-form .fi-otp-input-ctn input, .tpz-auth-otp-form .fi-one-time-code-input input {min-width:2.7rem;width:3.5rem;min-height:3.8rem;border-radius:.8rem;font-weight:800;}
@media(min-width:901px) and (max-height:850px) {.tpz-auth-hero {padding-top:2rem;padding-bottom:1.3rem}.tpz-auth-hero-message {margin-top:2rem}.tpz-auth-modules {margin-top:1.5rem;gap:.5rem 0}.tpz-auth-module {font-size:.75rem}.tpz-auth-hero-bottom {padding-top:.8rem}}
@media(max-width:900px) {
 body:has(.tpz-auth-hero) .fi-simple-layout {padding:1.25rem 1rem 2rem;display:flex;flex-direction:column;gap:1rem;}
 body:has(.tpz-auth-hero) .fi-simple-main {max-width:36rem;padding:1.7rem 1.5rem;border-radius:1.25rem;}
 .tpz-auth-hero {padding:1.5rem;width:100%;max-width:36rem;min-height:unset;}
 .tpz-auth-hero:after {display:none;}
 .tpz-auth-identity {margin-bottom:1rem;}
}
@media(max-width:420px) {
 .tpz-auth-otp-form .fi-otp-input-ctn input,.tpz-auth-otp-form .fi-one-time-code-input input {min-width:2.05rem;width:2.55rem;min-height:3.1rem;}
 body:has(.tpz-auth-hero) .fi-simple-main {padding:1.4rem 1.1rem;}
}


/* Final owner UAT polish: target the actual enterprise-mark component and Filament buttons.
   Both the panel login and standalone OTP layout include this shared stylesheet. */
body:has(.tpz-auth-hero) .tpz-auth-form-brand { margin:0 auto 1.5rem!important;display:flex;justify-content:center;align-items:center;text-align:center; }
body:has(.tpz-auth-hero) .tpz-enterprise-mark {display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.25rem;min-width:0;}
body:has(.tpz-auth-hero) .tpz-enterprise-mark-logo {display:block;width:auto;max-width:14rem;max-height:5.4rem;object-fit:contain;margin:0 auto .25rem;}
body:has(.tpz-auth-hero) .tpz-enterprise-mark-monogram {display:block;color:#082a60!important;font-size:clamp(3.7rem,5.5vw,5.1rem)!important;font-weight:950;font-style:italic;letter-spacing:-.13em;line-height:1;position:relative;padding:0 .22em .04em 0;text-shadow:0 1px 1px rgba(5,33,76,.08);}
body:has(.tpz-auth-hero) .tpz-enterprise-mark-monogram::after {content:"";position:absolute;left:.35em;top:-.06em;width:1.12em;height:.43em;border-top:.085em solid #159bea;border-right:.065em solid #159bea;border-radius:80% 90% 0 0;transform:rotate(-17deg);pointer-events:none;}
body:has(.tpz-auth-hero) .tpz-enterprise-mark-name {display:block;color:#082957!important;font-size:clamp(1rem,1.65vw,1.26rem)!important;font-weight:850!important;line-height:1.3;letter-spacing:-.022em;white-space:normal;}
body:has(.tpz-auth-hero) .tpz-enterprise-mark-name span {color:#1499e4;}
/* Filament v4 button background may be applied by a variant class or its inner element.
   Selector is scoped to guest auth and excludes the secondary email-OTP action. */
body:has(.tpz-auth-hero) .fi-simple-main button.fi-btn[type="submit"],
body:has(.tpz-auth-hero) .fi-simple-main .fi-form-actions button.fi-btn,
body:has(.tpz-auth-hero) .fi-simple-main button.tpz-auth-submit,
body:has(.tpz-auth-hero) .fi-simple-page-content button.tpz-auth-submit {
    --primary-500:#082e65;--primary-600:#082e65;--primary-700:#0c4488;
    background:#082e65!important;background-color:#082e65!important;
    background-image:linear-gradient(105deg,#071f49,#0c387a)!important;
    color:#fff!important;border:1px solid #082e65!important;
    box-shadow:0 6px 12px rgba(6,39,88,.14)!important;
    min-height:3.5rem!important;border-radius:.78rem!important;
}
body:has(.tpz-auth-hero) .fi-simple-main button.fi-btn[type="submit"]:hover,
body:has(.tpz-auth-hero) .fi-simple-main .fi-form-actions button.fi-btn:hover,
body:has(.tpz-auth-hero) .fi-simple-main button.tpz-auth-submit:hover,
body:has(.tpz-auth-hero) .fi-simple-page-content button.tpz-auth-submit:hover {
    background:#0c4488!important;background-image:none!important;
}
body:has(.tpz-auth-hero) button.fi-btn[type="submit"] > *,
body:has(.tpz-auth-hero) button.tpz-auth-submit > *,
body:has(.tpz-auth-hero) button.fi-btn[type="submit"] span,
body:has(.tpz-auth-hero) button.tpz-auth-submit span {color:#fff!important;}
body:has(.tpz-auth-hero) .fi-simple-main .fi-input-wrp,
body:has(.tpz-auth-hero) .fi-simple-page-content .fi-input-wrp {border:1.5px solid #c7d4e5!important;background:#fbfcff!important;}
body:has(.tpz-auth-hero) .fi-simple-main .fi-input-wrp:focus-within,
body:has(.tpz-auth-hero) .fi-simple-page-content .fi-input-wrp:focus-within {border-color:#338cd5!important;box-shadow:0 0 0 3px rgba(31,135,219,.11)!important;}
body:has(.tpz-auth-hero) .fi-one-time-code-input {gap:clamp(.4rem,1vw,.75rem)!important;justify-content:flex-start!important;}
body:has(.tpz-auth-hero) .fi-one-time-code-input input {border:1.5px solid #c7d4e5!important;border-radius:.65rem!important;min-width:2.9rem;max-width:3.8rem;min-height:3.6rem;background:#fbfcff;font-size:1.35rem!important;}
body:has(.tpz-auth-hero) .fi-one-time-code-input input:focus {border-color:#218ddd!important;outline:2px solid rgba(31,135,219,.17)!important;outline-offset:1px;}
/* Blend a subdued architectural backdrop into the bottom of the hero, behind the module icons. */
.tpz-auth-hero::after {height:35%;opacity:.35;background:linear-gradient(to bottom,#0a2c59 0%,rgba(7,30,66,.73) 30%,rgba(4,23,53,.73) 100%),url('/branding/tpz-erp-building-background.svg') center 60%/cover no-repeat;pointer-events:none;z-index:0;}
.tpz-auth-hero > * {position:relative;z-index:1;}
@media(max-width:900px) {
 body:has(.tpz-auth-hero) .tpz-enterprise-mark-monogram {font-size:3.7rem!important;}
 body:has(.tpz-auth-hero) .tpz-auth-form-brand {margin-bottom:1rem!important;}
 .tpz-auth-hero::after {display:none;}
}
@media(max-width:420px) {
 body:has(.tpz-auth-hero) .fi-one-time-code-input {gap:.3rem!important;}
 body:has(.tpz-auth-hero) .fi-one-time-code-input input {min-width:2.25rem;max-width:2.8rem;min-height:3.1rem;}
}

</style>
@endonce