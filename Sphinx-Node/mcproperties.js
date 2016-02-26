/*
 * Sphinx Minecraft Properties file.
 * For manipulating server.properties files.
 */

var fs = require("fs");

var McProperties = function (properties) {
	this.rawProps = properties;
	this.props = {};
	
	// Parse properties file.
	this.parse();
}

/**
 * Parse the raw properties file.
 */
McProperties.prototype.parse = function () {
	var lines = this.rawProps.split("\n");
	
	for (var i = 0; i < lines.length; i++) {
		var line = lines[i];
		
		if (line.indexOf("=") < 0) {
			// Useless line.
			continue;
		}
		
		var key = line.substring(0, line.indexOf("=")).trim();
		var value = line.substring(line.indexOf("=")+1).trim();
		
		this.props[key] = value;
	}
}

/**
 * Compile the properties.
 * @returns {String} Properties file content.
 */
McProperties.prototype.compile = function () {
	var _this = this;
	
	var lines = [
		"# DO NOT MODIFY. This file is auto generated by Sphinx.",
		"# Any changes will be overwritten on next launch.",
		"# Last modified: " + new Date().toString()
	];
	
	Object.keys(this.props).forEach(function (key) {
		var value = _this.props[key];
		
		lines.push(key + "=" + value);
	});
	
	return lines.join("\r\n");
}

/**
 * Set a value.
 * @param {String} Property key.
 * @param {String} Property value.
 */
McProperties.prototype.set = function (key, value) {
	this.props[key] = value;
}

/**
 * Get a value.
 * @param {String} Property key.
 */
McProperties.prototype.get = function (key) {
	return this.props[key];
}


module.exports = McProperties;