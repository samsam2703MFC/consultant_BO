# Sources de l'assistant de campagne

Copie des sources front du module marketing (React + Vite), gardée ici pour
que l'assistant du cockpit reste recompilable après la suppression du module
d'origine.

Recompiler et installer :

    npm install
    npx vite build --config vite.assistant.config.ts
    rm -rf ../../public/assistant/assets
    cp -r dist-assistant/assets ../../public/assistant/assets
    cp dist-assistant/assistant.html ../../public/assistant/index.html

L'entrée est `src/assistant-main.tsx` (montage de CampaignBuilder seul) ;
l'API appelée est `../api/v1/marketing/…`, servie par
`public/api/marketing/` (copie de l'API PHP du module, connectée à la base
du cockpit).
