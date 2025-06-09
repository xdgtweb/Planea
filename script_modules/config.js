// script_modules/config.js

// Asegúrate de que esta sea la ruta correcta a tu api.php desde la raíz de tu sitio web.
// Si index.html y api.php están en la misma carpeta raíz, 'api.php' es correcto.
export const API_BASE_URL = 'api.php'; 

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