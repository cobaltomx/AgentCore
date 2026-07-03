'use strict';

/**
 * WhatsApp Response Builder — Fase 3
 *
 * El LLM genera texto plano. Este módulo decide si la respuesta
 * debe ir como texto simple, botones, lista de slots, etc.
 *
 * Estrategia de enriquecimiento:
 * 1. Si la respuesta viene de check_availability → lista interactiva de slots
 * 2. Si la respuesta es confirmación de cita → botones Confirmar/Cancelar
 * 3. Si la respuesta es pregunta sí/no → botones
 * 4. En el resto de casos → texto con formato markdown ligero
 *
 * WhatsApp soporta: *negrita*, _cursiva_, ~tachado~, ```código```, listas con -
 */

const { slotsToWhatsAppList } = require('./meta-client');

/**
 * Construye la respuesta óptima de WhatsApp basada en el resultado del agente
 *
 * @param {Object} params
 * @param {string} params.text          - Texto de respuesta del LLM
 * @param {string} params.toolName      - Nombre del tool que se ejecutó (si hubo)
 * @param {Object} params.toolResult    - Resultado del tool
 * @param {Object} params.session       - Sesión actual
 * @param {Object} params.metaClient    - Instancia de MetaWhatsAppClient
 * @param {string} params.to            - Número destino
 * @returns {Promise<void>}
 */
async function sendAgentResponse({ text, toolName, toolResult, session, metaClient, to }) {

  // ── 1. Slots de disponibilidad → lista interactiva ────────────
  if (toolName === 'check_availability' && toolResult?.hasSlots && toolResult?.slots?.length > 0) {
    await sendAvailabilityResponse(metaClient, to, toolResult, text);
    return;
  }

  // ── 2. Cita agendada → botones de siguiente acción ────────────
  if (toolName === 'schedule_appointment' && toolResult?.success) {
    await metaClient.sendText(to, formatText(text));
    await metaClient.sendButtons(
      to,
      '¿Necesitas algo más?',
      [
        { id: 'action_done',       title: '✅ Es todo, gracias' },
        { id: 'action_reschedule', title: '🔄 Cambiar cita' },
        { id: 'action_human',      title: '👤 Hablar con alguien' },
      ]
    );
    return;
  }

  // ── 3. Detectar preguntas sí/no en el texto ───────────────────
  if (isYesNoQuestion(text) && text.length < 300) {
    const buttons = buildYesNoButtons(text);
    if (buttons) {
      await metaClient.sendButtons(to, formatText(text), buttons);
      return;
    }
  }

  // ── 4. Menú de bienvenida (primer mensaje) ────────────────────
  if (toolName === null && session.turnCount === 0) {
    await sendWelcomeMenu(metaClient, to, text, session);
    return;
  }

  // ── 5. Texto enriquecido (default) ───────────────────────────
  await metaClient.sendText(to, formatText(text));
}

// ─── Builders específicos ────────────────────────────────────────────────────

async function sendAvailabilityResponse(metaClient, to, toolResult, llmText) {
  const { slots } = toolResult;

  if (slots.length <= 3) {
    // Pocos slots → botones (más rápido de usar)
    const buttons = slots.slice(0, 3).map((s, i) => ({
      id:    `slot_${i}_${s.time}`,
      title: s.display?.split(' a las ').pop() || `Opción ${i + 1}`,
    }));

    await metaClient.sendButtons(
      to,
      llmText || 'Estos son los horarios disponibles:',
      buttons,
      { footer: 'Toca un horario para seleccionarlo' }
    );

  } else {
    // Muchos slots → lista interactiva agrupada por día
    const sections = slotsToWhatsAppList(slots);

    await metaClient.sendList(
      to,
      llmText || 'Selecciona el horario que más te convenga:',
      'Ver horarios 📅',
      sections,
      {
        header: '📅 Horarios disponibles',
        footer: 'Los horarios se reservan al instante',
      }
    );
  }
}

async function sendWelcomeMenu(metaClient, to, greetingText, session) {
  // Primero enviar el saludo
  await metaClient.sendText(to, formatText(greetingText));

  // Después un menú rápido de opciones comunes
  await metaClient.sendButtons(
    to,
    '¿En qué puedo ayudarte hoy?',
    [
      { id: 'menu_appointment', title: '📅 Agendar cita' },
      { id: 'menu_info',        title: 'ℹ️ Información' },
      { id: 'menu_human',       title: '👤 Hablar con alguien' },
    ]
  );
}

// ─── Utilidades ─────────────────────────────────────────────────────────────

/**
 * Aplica formato WhatsApp al texto del LLM
 * - Convierte **bold** → *bold*
 * - Convierte listas numeradas en listas con emoji
 * - Limita longitud si es necesario
 */
function formatText(text) {
  if (!text) return '';

  return text
    .replace(/\*\*(.*?)\*\*/g, '*$1*')           // markdown bold → WA bold
    .replace(/#{1,6}\s(.+)/g, '*$1*')            // headers → bold
    .replace(/^(\d+)\.\s/gm, '▸ ')              // listas numeradas → ▸
    .replace(/\n{3,}/g, '\n\n')                  // máximo 2 saltos de línea
    .trim()
    .substring(0, 4096);                          // límite de Meta
}

/**
 * Detecta si el texto del LLM termina en una pregunta sí/no
 */
function isYesNoQuestion(text) {
  const lower = text.toLowerCase().trim();
  const yesNoPatterns = [
    /¿te (funciona|viene|parece|gustaría|interesa)/,
    /¿(confirmas|quieres|deseas|puedes)/,
    /¿(está bien|de acuerdo|correcto)/,
    /¿(algo más|necesitas algo|puedo ayudarte)/,
    /(¿sí o no\?)/,
  ];
  return yesNoPatterns.some(p => p.test(lower));
}

/**
 * Construye botones sí/no según el contexto del texto
 */
function buildYesNoButtons(text) {
  const lower = text.toLowerCase();

  if (lower.includes('confirmas') || lower.includes('confirmar')) {
    return [
      { id: 'confirm_yes', title: '✅ Sí, confirmar' },
      { id: 'confirm_no',  title: '❌ No, cancelar' },
    ];
  }

  if (lower.includes('algo más') || lower.includes('puedo ayudarte')) {
    return [
      { id: 'more_yes',  title: '✅ Sí, tengo otra pregunta' },
      { id: 'more_no',   title: '👋 No, gracias' },
    ];
  }

  if (lower.includes('te funciona') || lower.includes('te viene')) {
    return [
      { id: 'slot_ok',     title: '✅ Sí, ese horario' },
      { id: 'slot_other',  title: '🔄 Ver otros horarios' },
    ];
  }

  return null; // sin botones contextuales
}

/**
 * Traduce respuestas de botones del usuario en texto para el LLM
 * El LLM siempre recibe texto, nunca IDs de botones
 */
function buttonResponseToText(buttonId, buttonTitle) {
  const mappings = {
    'menu_appointment': 'Quiero agendar una cita',
    'menu_info':        'Necesito información sobre sus servicios',
    'menu_human':       'Quiero hablar con una persona',
    'confirm_yes':      'Sí, confirmo',
    'confirm_no':       'No, quiero cancelar',
    'more_yes':         'Sí, tengo otra pregunta',
    'more_no':          'No, gracias, eso es todo',
    'slot_ok':          'Sí, ese horario me funciona',
    'slot_other':       'Quiero ver otros horarios disponibles',
    'action_done':      'No, es todo, gracias',
    'action_reschedule':'Quiero cambiar mi cita',
    'action_human':     'Quiero hablar con alguien del equipo',
  };

  // Slots tienen formato slot_N_ISO
  if (buttonId?.startsWith('slot_') && buttonId.split('_').length >= 3) {
    const parts = buttonId.split('_');
    const slotIndex = parseInt(parts[1]);
    return `Quiero la opción ${slotIndex + 1}: ${buttonTitle}`;
  }

  return mappings[buttonId] || buttonTitle || 'Continuar';
}

/**
 * Traduce selección de lista en texto para el LLM
 */
function listResponseToText(listRowId, listTitle, listDescription) {
  // Slots tienen formato slot_FECHA_N
  if (listRowId?.startsWith('slot_')) {
    return `Quiero agendar en el horario: ${listTitle}${listDescription ? ' - ' + listDescription : ''}`;
  }
  return listTitle || 'Selección realizada';
}

module.exports = {
  sendAgentResponse,
  formatText,
  buttonResponseToText,
  listResponseToText,
  sendAvailabilityResponse,
};
