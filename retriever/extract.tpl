<?xml version="1.0" encoding="utf-8"?>
<xsl:stylesheet xmlns:xsl="http://www.w3.org/1999/XSL/Transform" version="1.0">

  <xsl:template match="text()"/>

  <xsl:template match="$match">
    <xsl:copy>
      <xsl:apply-templates select="node()|@*" mode="remove"/>
    </xsl:copy>
  </xsl:template>

  <xsl:template match="node()|@*" mode="remove">
    <xsl:copy>
      <xsl:apply-templates select="node()|@*" mode="remove"/>
    </xsl:copy>
  </xsl:template>

{{ if $remove }}
  <xsl:template match="$remove" mode="remove"/>
{{ endif }}

  <!-- attempt to replace relative URLs with absolute URLs -->
  <!-- http://stackoverflow.com/questions/3824631/replace-href-value-in-anchor-tags-of-html-using-xslt -->

  <xsl:template match="*/@src[starts-with(.,'.')]" mode="remove">
    <xsl:attribute name="src">
      <xsl:value-of select="concat('$dirurl',.)"/>
    </xsl:attribute>
  </xsl:template>
  <xsl:template match="*/@src[starts-with(.,'/')]" mode="remove">
    <xsl:attribute name="src">
      <xsl:value-of select="concat('$rooturl',.)"/>
    </xsl:attribute>
  </xsl:template>

</xsl:stylesheet>