
.PHONY: doc

doc: user_guide.html administrator_guide.html developer_guide.html

user_guide.html: user_guide.rst
	rst2html --stylesheet-path=user_guide_style.css $+ > $@
	cp $@ ../source

administrator_guide.html: administrator_guide.rst
	rst2html --date --stylesheet-path=guide_style.css $+ > $@

developer_guide.html: developer_guide.rst
	rst2html --date --stylesheet-path=guide_style.css $+ > $@

developer_guide.tex: developer_guide.rst
	rst2latex --documentclass=book --use-latex-toc --use-latex-footnotes --literal-block-env=verbatim --reference-label="ref*" $+ > $@
