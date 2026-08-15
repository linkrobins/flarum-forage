const path = require('path');

// flarum-webpack-config (this version) resolves entrypoints at the js/ root.
// We keep TypeScript source under src/, so point the entry there explicitly.
// Admin only: nothing about this extension changes the forum frontend.
const config = require('flarum-webpack-config')();

config.entry = {
    admin: path.resolve(__dirname, 'src/admin.ts'),
};

// This flarum-webpack-config version externalizes flarum/* and jquery but not
// mithril; Flarum exposes mithril as the global `m`, so map the import to it.
config.externals = [{ mithril: 'm' }, ...config.externals];

module.exports = config;
