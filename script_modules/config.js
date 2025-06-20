// script_modules/config.js

// Asegúrate de que esta sea la ruta correcta a tu api.php desde la raíz de tu sitio web.
// Si api.php está en la raíz de tu dominio (ej. www.tudominio.com/api.php), usa '/api.php'.
export const API_BASE_URL = '/api.php'; // <--- RUTA AJUSTADA PARA LA RAÍZ DEL SERVIDOR

export const EMOJIS_PREDEFINIDOS = Object.freeze([ 
    '📌', '❗', '🎉', '🎂', '💼', '✈️', '❤️', '💪', 
    '💡', '💰', '🛒', '🏃', '🥂', '🎁', '🎓', '💍', 
    '💔', '😔', '🥰', '🤣', '🤔', '😴', '🤢', '🥳', 
    '🤯', '🤫', '🙏', '✨', '✅', '❌', '🔍', '📞',
    '✉️', '⏰', '🛠️', '🔑', '🔒', '💡', '🎵', '🎮',
    '⚽', '🏀', '🍕', '☕', '🍻', '🏖️', '⛰️', '🏠'
]);

// No debería haber más declaraciones de API_BASE_URL en este archivo.
// Puedes añadir otras constantes aquí si las necesitas, pero cada una con un nombre único.