const defaultConfig = require("@wordpress/scripts/config/webpack.config");
const WooCommerceDependencyExtractionWebpackPlugin = require("@woocommerce/dependency-extraction-webpack-plugin");

module.exports = {
  ...defaultConfig,
  entry: {
    blocks: "./assets/src/blocks.js",
    admin: "./assets/src/admin.js",
  },
  output: {
    ...defaultConfig.output,
    path: require("path").resolve(process.cwd(), "assets/build"),
  },
  plugins: [
    ...defaultConfig.plugins.filter(
      (plugin) =>
        plugin.constructor.name !== "DependencyExtractionWebpackPlugin",
    ),
    new WooCommerceDependencyExtractionWebpackPlugin(),
  ],
};
