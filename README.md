# DespliegaGit

> **"De tu repositorio a WordPress en un solo paso: pega el enlace, despliega y publica."**

DespliegaGit es una herramienta de automatización ligera para WordPress que permite realizar pulls y despliegues directamente desde repositorios de GitHub mediante su API / webhooks. Facilita la sincronización de ramas, el despliegue de builds de producción y la carga de bundles web sin necesidad de acceder a la consola del servidor (SSH/FTP).

---

## 🚀 Características Principales

- **Importación directa por URL:** Introduce el enlace del repositorio y la rama deseada (`main`/`master` o personalizada).
- **Compatibilidad versátil:** Importa sitios estáticos (HTML/CSS/JS), bundles compilados (Vite, React, Vue, Astro), componentes, plantillas o temas/plugins completos.
- **Soporte para repositorios privados:** Conexión segura mediante *Personal Access Token* (PAT) de GitHub, cifrado con AES-256 en base de datos.
- **Despliegues limpios:** Descarga, descomprime y ubica los archivos en la ruta adecuada sin sobreescribir configuraciones críticas (`.env`, `wp-config.php`, etc.).
- **Modo previsualización:** Comprueba la estructura importada y navega por el proyecto en borrador antes de publicarlo definitivamente.
- **Automatización vía Webhooks:** Sincronización continua en cada `git push` con validación de firma HMAC-SHA256 y ejecución en segundo plano con WP-Cron.
- **Rollback instantáneo:** Respaldo comprimido antes de cada actualización para volver a una versión previa con un solo clic.
- **Soporte SPA Fallback:** Redirección automática a `index.html` para aplicaciones basadas en enrutamiento cliente (React Router, Vue Router, etc.).

---

## 📂 Arquitectura del Plugin

```
DespliegaGit/
├── despliegagit.php             # Punto de entrada y hooks del ciclo de vida
├── readme.txt                   # Metadatos para el directorio de WordPress.org
├── README.md                    # Documentación técnica para desarrolladores
├── assets/
│   ├── css/admin.css            # Estilos del panel de administración
│   └── js/admin.js              # Lógica asíncrona, modales y llamadas AJAX
├── includes/
│   ├── class-dg-crypto.php      # Cifrado AES-256 de tokens con sales de WordPress
│   ├── class-dg-settings.php    # Gestión de ajustes globales y listas de exclusión
│   ├── class-dg-projects.php    # CRUD de repositorios, validaciones y modelos
│   ├── class-dg-github.php      # Cliente REST de la API de GitHub
│   ├── class-dg-deployer.php    # Motor de descarga, staging, sincronización y rollback
│   ├── class-dg-static-server.php # Despacho y rewrite rules para sitios estáticos y SPAs
│   ├── class-dg-webhook.php     # Endpoint REST para webhooks con firma HMAC
│   └── class-dg-admin.php       # Interfaz gráfica y controladores AJAX
└── views/
    ├── dashboard.php            # Panel principal de proyectos y modales
    └── settings.php             # Pantalla de configuración global
```

---

## 🛠️ Instalación y Requisitos

- **PHP:** 7.4 o superior (compatible con PHP 8.0, 8.1, 8.2 y 8.3).
- **WordPress:** 5.8 o superior.
- **Extensiones de PHP recomendadas:** `openssl`, `zip`, `json`, `curl`.

### Instalación:
1. Clona o copia esta carpeta dentro del directorio de plugins de WordPress:
   ```bash
   cd wp-content/plugins/
   git clone https://github.com/tu-usuario/DespliegaGit.git
   ```
2. Activa el plugin desde el panel de **Plugins** de WordPress o vía WP-CLI:
   ```bash
   wp plugin activate despliegagit
   ```

---

## ⚡ Automatización con GitHub Webhooks

Para habilitar despliegue continuo tras cada `push`:

1. En el panel de DespliegaGit, abre el menú contextual del proyecto y selecciona **Ver datos de Webhook**.
2. En tu repositorio de GitHub, ve a **Settings** &rarr; **Webhooks** &rarr; **Add webhook**.
3. Pega la **Payload URL** generada:
   ```
   https://tudominio.com/wp-json/despliegagit/v1/webhook/{id-del-proyecto}
   ```
4. Selecciona `application/json` como **Content type**.
5. Introduce el **Secret** proporcionado.
6. Marca el evento **Just the push event** y guarda.

---

## 🛡️ Seguridad

- **Cifrado en reposo:** Los tokens de acceso de GitHub se almacenan cifrados con AES-256-CBC derivado de las claves secretas `AUTH_KEY` y `SECURE_AUTH_SALT` de `wp-config.php`.
- **Validación de Webhooks:** Verificación de firma criptográfica `X-Hub-Signature-256` en cada push entrante.
- **Protección Zip-Slip:** Comprobación estricta de rutas relativas al descomprimir archivos.
- **Protección de rutas:** Validación de límites de directorio para evitar cualquier Directory Traversal.
- **Aislamiento:** Carpetas de backup protegidas contra acceso directo por navegador.

---

## 📄 Licencia

Este proyecto está bajo la licencia **GPLv2 o posterior**.
