window.MathJax = {
	tex: {
		// Deliberately not enabling single "$...$" delimiters: this is an AI/
		// funding-heavy news site, so bare "$" shows up in ordinary prose
		// (grant amounts, pricing) far more often than as math. "\( \)" and
		// "$$ $$"/"\[ \]" avoid that ambiguity entirely.
		inlineMath: [ [ '\\(', '\\)' ] ],
		displayMath: [
			[ '\\[', '\\]' ],
			[ '$$', '$$' ]
		],
		processEscapes: true,
		tags: 'ams'
	},
	options: {
		skipHtmlTags: [ 'script', 'noscript', 'style', 'textarea', 'pre', 'code' ]
	},
	svg: {
		fontCache: 'global'
	}
};
