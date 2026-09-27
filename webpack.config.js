const path = require('path');
const { VueLoaderPlugin } = require('vue-loader');
const webpack = require('webpack');

const elementRelease = require('./element-release.json');
const RIOT_WEB_VERSION = elementRelease.tag;
const RIOT_WEB_HASH = elementRelease.tag;

module.exports = {
    entry: {
        adminSettings: path.join(__dirname, 'src', 'adminSettings.js'),
        main: path.join(__dirname, 'src', 'main.js'),
        logout: path.join(__dirname, 'src', 'logout.js'),
    },
    output: {
        path: path.join(__dirname, 'js'),
        publicPath: '/js/',
        clean: true,
    },
    devtool: 'source-map',
    mode: process.env.NODE_ENV === 'production' ? 'production' : 'development',
    module: {
        rules: [
            {
                test: /\.css$/,
                use: ['vue-style-loader', 'css-loader']
            },
            {
                test: /\.scss$/,
                use: ['vue-style-loader', 'css-loader', 'sass-loader']
            },
            {
                test: /\.vue$/,
                loader: 'vue-loader'
            },
            {
                test: /\.js$/,
                loader: 'babel-loader',
                exclude: /node_modules/
            },
        ],
    },
    plugins: [
        new VueLoaderPlugin(),
        new webpack.DefinePlugin({
            RIOT_WEB_HASH: JSON.stringify(RIOT_WEB_HASH),
            RIOT_WEB_VERSION: JSON.stringify(RIOT_WEB_VERSION),
        }),
    ],
    resolve: {
        extensions: ['.js', '.vue'],
    },
};
