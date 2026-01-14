import { defineConfig, loadEnv } from "vite";
import { networkInterfaces } from "os";
import phpVitePlugin from "php-vite-plugin";
import FullReload from "vite-plugin-full-reload";
import path from "path";

const nets = networkInterfaces();
const localIPs = Object.create({});

for (const name of Object.keys(nets)) {
   const netList = nets[name];
   if (!netList) continue;
   for (const net of netList) {
       // Skip over non-IPv4 and internal (i.e. 127.0.0.1) addresses
       // 'IPv4' is in Node <= 17, from 18 it's a number 4 or 6
       const familyV4Value = typeof net.family === "string" ? "IPv4" : 4;

       if (net.family === familyV4Value && !net.internal) {
           if (!localIPs[name]) {
               localIPs[name] = [];
           }
           // localIPs[name].push(net.address);
           localIPs[name] = net.address;
       }
   }
}

export default defineConfig(({ mode }) => {
   // Load env variables for the current mode (development by default)
   const env = loadEnv(mode, path.resolve(__dirname, "config"), "");
   const appUrl = env.APP_URL || process.env.DDEV_PRIMARY_URL || "http://127.0.0.1:8765";

   return {
       plugins: [
           phpVitePlugin({
               envFile: path.resolve(__dirname, "config/.env"), // custom env location
               phpUrl: appUrl, // fallback if no APP_URL in env
               publicDir: "webroot/build",
               input: [
                    path.resolve(__dirname, "resources/js/app.js"),
                    path.resolve(__dirname, "resources/css/app.css"),
                ]
           }),
           FullReload(["templates/**/*"]),
       ],
       server: {
           host: process.env.DDEV_HOSTNAME || (localIPs["Ethernet"] ?? localIPs["Wi-Fi"] ?? "0.0.0.0"),
           port: 5173
       },
   };
});
