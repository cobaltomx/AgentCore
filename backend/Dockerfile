# Imagen de PRODUCCIÓN del backend AgentCore.
# Diferencias vs Dockerfile.dev: solo dependencias de producción, sin nodemon,
# usuario no-root, arranque directo con node.
FROM node:20-alpine

# dumb-init para manejo correcto de señales (SIGTERM → apagado limpio de workers)
RUN apk add --no-cache dumb-init

WORKDIR /app

# Dependencias primero (capa cacheable). npm ci = build reproducible desde el lock.
COPY package*.json ./
RUN npm ci --omit=dev && npm cache clean --force

# Código de la app
COPY . .

# Correr como usuario sin privilegios (el usuario 'node' viene en la imagen base)
USER node

ENV NODE_ENV=production
EXPOSE 3000

ENTRYPOINT ["dumb-init", "--"]
CMD ["node", "src/app.js"]
