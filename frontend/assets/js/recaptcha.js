/**
 * reCAPTCHA v3 (invisible) — helper reusable para login/forgot/reset.
 * Solo se activa si window.RECAPTCHA_SITE_KEY está definida (config.php la
 * imprime condicionalmente) — sin site key, getRecaptchaToken() resuelve ''
 * y el backend simplemente no la exige (degradación elegante).
 *
 * Uso: const token = await getRecaptchaToken('login');
 */
function getRecaptchaToken(action) {
  const siteKey = window.RECAPTCHA_SITE_KEY;
  if (!siteKey || !window.grecaptcha) return Promise.resolve('');
  return new Promise((resolve) => {
    grecaptcha.ready(() => {
      grecaptcha.execute(siteKey, { action })
        .then(resolve)
        .catch(() => resolve('')); // si falla, dejamos que el backend decida (fail-open)
    });
  });
}
