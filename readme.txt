=== DespliegaGit ===
Contributors: oscar
Donate link: https://github.com/despliegagit/despliegagit
Tags: github, deploy, git, static site, import, automation, webhook
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

De tu repositorio a WordPress en un solo paso: pega el enlace, despliega y publica.

== Description ==

**DespliegaGit conecta tu sitio de WordPress directamente con GitHub. Pega el enlace de cualquier repositorio público o privado y clona, importa y publica tu web o componentes en segundos, sin complicaciones técnicas.**

### DespliegaGit – Clona e importa proyectos de GitHub a WordPress con un solo clic

DespliegaGit simplifica el flujo entre el código y tu sitio en vivo. Diseñado para desarrolladores, creadores y diseñadores que quieren evitar clientes FTP o procesos manuales de carga, este plugin permite convertir cualquier repositorio de GitHub en contenido funcional dentro de WordPress con solo pegar la URL.

### Características principales:

* **Importación directa por URL:** Solo introduce el enlace del repositorio y la rama deseada (main/master o personalizada). Detección inteligente de subcarpetas (`dist`, `build`, `public`).
* **Compatibilidad versátil:** Importa sitios estáticos (HTML/CSS/JS), aplicaciones cliente (React, Vue, Vite, Astro), plantillas, componentes o temas y plugins completos.
* **Soporte para repositorios privados:** Conexión segura mediante Personal Access Token (PAT) de GitHub cifrado con AES-256 en base de datos.
* **Despliegues limpios:** Descarga, descomprime y ubica los archivos en la ruta adecuada sin sobreescribir configuraciones críticas (`.env`, `wp-config.php`, etc.).
* **Modo previsualización:** Comprueba la estructura importada y navega por el sitio en modo borrador antes de publicarlo definitivamente.
* **Despliegue automático por Webhook:** Mantén tu web siempre sincronizada activando el webhook de GitHub para que cada `git push` publique los cambios de inmediato.
* **Copias de seguridad automáticas y Rollback:** Vuelve a la versión anterior con un solo clic si algo sale mal.
* **Soporte SPA (Single Page Applications):** Enrutamiento fallback a `index.html` para aplicaciones creadas con React Router, Vue Router u otros frameworks modernos.

== Installation ==

1. Sube la carpeta `DespliegaGit` al directorio `/wp-content/plugins/` o instala el archivo ZIP desde **Plugins > Añadir nuevo > Subir plugin**.
2. Activa el plugin a través del menú **Plugins** en WordPress.
3. Ve a **DespliegaGit** en el panel lateral de administración.
4. Haz clic en **Importar Repositorio**, pega el enlace de tu repositorio de GitHub y ¡listo!

== Frequently Asked Questions ==

= ¿Puedo importar repositorios privados? =
Sí. Puedes configurar un Personal Access Token (PAT) global en la sección de **Ajustes** de DespliegaGit o introducir un token individual al añadir el proyecto.

= ¿Dónde se guardan los sitios estáticos importados? =
Se almacenan de forma aislada en `wp-content/uploads/despliegagit/sites/[slug]/` y se sirven bajo URLs limpias como `https://tudominio.com/tu-proyecto/`.

= ¿Se sobrescribirán archivos de configuración si actualizo? =
No. Puedes configurar la lista de archivos a preservar (por ejemplo `.env` o archivos de configuración local) para que nunca se sobrescriban durante los despliegues.

= ¿Puedo revertir una actualización fallida? =
Sí. DespliegaGit genera una copia de seguridad automática antes de cada despliegue. Puedes hacer clic en **Restaurar último backup** en cualquier momento.

== Screenshots ==

1. Panel principal de proyectos con estado, ramas y accesos rápidos de despliegue.
2. Modal para importar repositorio con detección inteligente de ramas y componentes.
3. Modo previsualización con explorador de archivos e inspección antes de publicar.

== Changelog ==

= 1.0.0 =
* Versión inicial de DespliegaGit.
* Importación directa por URL de repositorios públicos y privados.
* Servidor interno para sitios estáticos y SPAs.
* Soporte para temas y plugins.
* Despliegue automático vía GitHub Webhooks con firma HMAC.
* Sistema de rollback y copias de seguridad automáticas.

