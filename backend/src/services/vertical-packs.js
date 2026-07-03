'use strict';

/**
 * VerticalPacks — Packs pre-armados por industria
 *
 * Acelera el onboarding: un cliente nuevo elige su industria y obtiene un bot
 * funcional en minutos (prompt de arranque + FAQs + config), en vez de escribir
 * todo desde cero. Baja el CAC y sube la conversión de prueba a pago.
 *
 * Cada pack:
 *   - prompt:  plantilla de system prompt (usa {{business}} como placeholder)
 *   - config:  defaults sugeridos (tono, triage, etc.)
 *   - faqs:    preguntas frecuentes listas para la base de conocimiento
 */

const PACKS = {
  dental: {
    label: 'Clínica Dental',
    config: { tone: 'professional', enableTriage: true },
    prompt:
`Eres la recepcionista virtual de {{business}}, una clínica dental.
Tu objetivo es agendar citas, resolver dudas de tratamientos y precios, y atender urgencias.
Sé cálida, clara y profesional. Cuando alguien quiera agendar, muestra primero la disponibilidad
y pide los datos solo al confirmar. Para dolor intenso o urgencias, prioriza la atención.`,
    faqs: [
      { question: '¿Cuánto cuesta una limpieza dental?', answer: 'La limpieza dental tiene un costo accesible; el precio exacto depende de tu caso. Con gusto agendamos una valoración rápida.' },
      { question: '¿Atienden urgencias o dolor?', answer: 'Sí, atendemos urgencias dentales. Si tienes dolor intenso, dime y te damos prioridad para hoy mismo.' },
      { question: '¿Trabajan con brackets o alineadores?', answer: 'Ofrecemos ortodoncia con brackets y alineadores invisibles. En una valoración te decimos cuál te conviene.' },
      { question: '¿Aceptan tarjeta?', answer: 'Sí, aceptamos tarjetas de crédito y débito, además de efectivo.' },
      { question: '¿Qué horario tienen?', answer: 'Atendemos de lunes a viernes. Dime qué día te conviene y te muestro los horarios disponibles.' },
    ],
  },

  restaurante: {
    label: 'Restaurante',
    config: { tone: 'friendly' },
    prompt:
`Eres el asistente virtual de {{business}}, un restaurante.
Ayudas con reservaciones, el menú, horarios, ubicación y pedidos a domicilio.
Sé cálido y ágil. Para reservar, confirma fecha, hora y número de personas.
Si preguntan por el menú o precios, responde con lo que sabes y sugiere los platillos.`,
    faqs: [
      { question: '¿Puedo reservar una mesa?', answer: '¡Claro! Dime para qué día, hora y cuántas personas, y te aparto el lugar.' },
      { question: '¿Tienen menú vegetariano?', answer: 'Sí, contamos con opciones vegetarianas. Con gusto te comparto los platillos disponibles.' },
      { question: '¿Hacen entregas a domicilio?', answer: 'Sí, tenemos servicio a domicilio. Dime tu zona y te confirmo el tiempo estimado.' },
      { question: '¿Cuál es su horario?', answer: 'Estamos abiertos todos los días; dime qué día planeas venir y te confirmo el horario exacto.' },
      { question: '¿Dónde están ubicados?', answer: 'Con gusto te comparto nuestra ubicación. ¿Vienes en auto o necesitas referencia de transporte?' },
    ],
  },

  ecommerce: {
    label: 'Tienda / E-commerce',
    config: { tone: 'friendly' },
    prompt:
`Eres el asistente de ventas de {{business}}, una tienda en línea.
Ayudas a los clientes a encontrar productos, resolver dudas, rastrear pedidos y comprar.
Cuando alguien busque o quiera comprar algo, usa el catálogo: muestra productos, arma el
carrito y genera el link de pago. Sé claro con precios y disponibilidad.`,
    faqs: [
      { question: '¿Qué productos venden?', answer: 'Con gusto te muestro nuestro catálogo. ¿Buscas algo en particular o te muestro lo más popular?' },
      { question: '¿Cómo rastreo mi pedido?', answer: 'Dame tu número de pedido o el correo con el que compraste y reviso el estatus por ti.' },
      { question: '¿Tienen envío gratis?', answer: 'Ofrecemos envío gratis a partir de cierto monto. Dime tu zona y te confirmo el costo.' },
      { question: '¿Puedo devolver un producto?', answer: 'Sí, aceptamos devoluciones dentro del periodo indicado. Cuéntame qué pasó y te ayudo con el proceso.' },
      { question: '¿Qué formas de pago aceptan?', answer: 'Aceptamos tarjeta de crédito y débito mediante un link de pago seguro.' },
    ],
  },

  inmobiliaria: {
    label: 'Inmobiliaria',
    config: { tone: 'professional' },
    prompt:
`Eres el asistente de {{business}}, una inmobiliaria.
Ayudas a clientes a encontrar propiedades en venta o renta, agendar visitas y resolver dudas
de financiamiento. Pregunta zona, presupuesto y tipo de propiedad para orientar mejor.
Captura los datos de contacto del interesado para dar seguimiento.`,
    faqs: [
      { question: '¿Tienen propiedades en renta?', answer: '¡Sí! Dime la zona, el presupuesto y cuántas recámaras buscas, y te muestro opciones.' },
      { question: '¿Puedo agendar una visita?', answer: 'Con gusto. Dime qué propiedad te interesa y cuándo te queda bien, y coordinamos la visita.' },
      { question: '¿Trabajan con crédito Infonavit?', answer: 'Sí, trabajamos con Infonavit, Fovissste y crédito bancario. Te orientamos según tu caso.' },
      { question: '¿Cobran por mostrar propiedades?', answer: 'No, mostrar las propiedades no tiene costo. Con gusto te agendamos una visita.' },
      { question: '¿Cómo vendo mi propiedad con ustedes?', answer: 'Te hacemos una valuación y te acompañamos en todo el proceso. ¿Quieres que te contactemos?' },
    ],
  },

  servicios: {
    label: 'Servicios generales',
    config: { tone: 'professional' },
    prompt:
`Eres el asistente virtual de {{business}}.
Ayudas a los clientes con información de servicios, precios, horarios, y a agendar o cotizar.
Sé claro, cálido y resolutivo. Captura el nombre y teléfono del cliente cuando quiera
seguimiento o una cotización.`,
    faqs: [
      { question: '¿Qué servicios ofrecen?', answer: 'Con gusto te cuento. ¿Hay algo específico que necesites o te doy un panorama general?' },
      { question: '¿Cuánto cuesta?', answer: 'El precio depende de lo que necesites. Cuéntame más y te doy una cotización.' },
      { question: '¿Cuál es su horario?', answer: 'Dime qué día te conviene y te confirmo nuestra disponibilidad.' },
      { question: '¿Puedo agendar una cita?', answer: '¡Claro! Dime qué necesitas y cuándo te queda bien, y lo agendamos.' },
      { question: '¿Cómo los contacto?', answer: 'Puedes escribirme aquí mismo y te ayudo. Si quieres seguimiento, déjame tu nombre y teléfono.' },
    ],
  },
};

// Alias de industrias hacia un pack
const ALIASES = {
  dental: 'dental', clinica: 'dental',
  restaurante: 'restaurante', comida: 'restaurante',
  ecommerce: 'ecommerce', tienda: 'ecommerce', retail: 'ecommerce',
  inmobiliaria: 'inmobiliaria', bienes_raices: 'inmobiliaria',
  servicios: 'servicios', taller: 'servicios', educacion: 'servicios',
  gym: 'servicios', consultorio: 'servicios', general: 'servicios',
};

function packKeyFor(industry) {
  const k = String(industry || '').toLowerCase();
  return ALIASES[k] || 'servicios';
}

/** Devuelve el pack (con prompt resuelto con el nombre del negocio). */
function getPack(industry, businessName = 'tu negocio') {
  const key  = packKeyFor(industry);
  const pack = PACKS[key];
  return {
    key,
    label:  pack.label,
    config: pack.config,
    prompt: pack.prompt.replace(/\{\{business\}\}/g, businessName),
    faqs:   pack.faqs,
  };
}

function listPacks() {
  return Object.entries(PACKS).map(([key, p]) => ({ key, label: p.label, faqs: p.faqs.length }));
}

module.exports = { getPack, listPacks, packKeyFor };
