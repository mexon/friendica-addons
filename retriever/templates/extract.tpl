<?xml version="1.0" encoding="utf-8"?>
<xsl:stylesheet xmlns:xsl="http://www.w3.org/1999/XSL/Transform" version="1.0">

  <xsl:template match="text()"/>

{{function clause_xpath}}
  {{if !$clause.attribute}}
$clause.element
  {{elseif $clause.attribute == 'class'}}
$clause.element[contains(concat(' ', normalize-space(@class), ' '), '$clause.value')]
  {{else}}
$clause.element[@$clause.attribute]='$clause.value']
  {{/if}}
{{/function}}

{{if $spec}}
<!-- there is a spec -->
{{if $spec.include}}
<!-- there is a spec.include -->
{{foreach $spec.include as $clause}}
<!-- include: element $clause.element attribute $clause.attribute value $clause.value -->
{{foreach $clause as $k=>$v}}
<!-- dothis include $k => $v -->
{{/foreach}}
<!--
  <xsl:template match="{{clause_xpath clause=$clause}}">
    <xsl:copy>
      <xsl:apply-templates select="node()|@*" mode="remove"/>
    </xsl:copy>
  </xsl:template>
-->
{{/foreach}}
{{/if}}
{{if $spec.remove}}
<!-- there is a spec.include -->
{{foreach $spec.remove as $clause}}
<!-- include: element $clause.element attribute $clause.attribute value $clause.value -->
<!--
  <xsl:template match="{{clause_xpath clause=$clause}}"/>
-->
{{/foreach}}
{{/if}}
{{/if}}

  <xsl:template match="{{$include}}">
    <xsl:copy>
      <xsl:apply-templates select="node()|@*" mode="remove"/>
    </xsl:copy>
  </xsl:template>

  <xsl:template match="node()|@*" mode="remove">
    <xsl:copy>
      <xsl:apply-templates select="node()|@*" mode="remove"/>
    </xsl:copy>
  </xsl:template>

{{if $exclude}}
  <xsl:template match="{{$exclude}}" mode="remove"/>
{{/if}}

  <!-- attempt to replace relative URLs with absolute URLs -->
  <!-- http://stackoverflow.com/questions/3824631/replace-href-value-in-anchor-tags-of-html-using-xslt -->

  <xsl:template match="*/@src[starts-with(.,'.')]" mode="remove">
    <xsl:attribute name="src">
      <xsl:value-of select="concat('{{$dirurl}}',.)"/>
    </xsl:attribute>
  </xsl:template>
  <xsl:template match="*/@src[starts-with(.,'/')]" mode="remove">
    <xsl:attribute name="src">
      <xsl:value-of select="concat('{{$rooturl}}',.)"/>
    </xsl:attribute>
  </xsl:template>

</xsl:stylesheet>