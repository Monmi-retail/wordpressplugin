const path = require('path');
const DependencyExtractionWebpackPlugin = require('@woocommerce/dependency-extraction-webpack-plugin');

module.exports = {
    entry: {
        'monmi-blocks': path.resolve(__dirname, 'src/blocks/index.js'),
    },
    output: {
        path: path.resolve(__dirname, 'build'),
        filename: '[name].js',
    },
    plugins: [
        new DependencyExtractionWebpackPlugin(),
    ],
    resolve: {
        extensions: [ '.js' ],
    },
    mode: 'production',
};
