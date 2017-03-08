<?php

$config = [
  "title" => "Finanzantrag für ein Projekt der Studierendenschaft (internes Projekt)",
  "shortTitle" => "Projektantrag (intern)",
  "state" => [ "draft"      => [ "Entwurf", "Entwurf", ],
               "new"        => [ "Eingereicht", "einreichen" ],
             ],
  "createState" => "draft",
  "categories" => [
    "need-action" => [
       [ "state" => "draft", "hasPermission" => "isCorrectGremium" ],
    ],
  ],
  "permission" => [
    /* each permission has a name and a list of sufficient conditions.
     * Each condition is an AND clause.
     * This is merged with form data that can add extra permissions not given here
     * hasPermission: true if all given permissions are present
     * group: true if all given groups are present
     * field: true if all given checks are ok
     */
    "canBeLinked" => [
      [ "state" => "new" ],
    ],
    "canRead" => [
      [ "creator" => "self" ],
      [ "hasPermission" => "isCorrectGremium" ],
      [ "group" => "ref-finanzen" ],
      [ "group" => "konsul" ],
    ],
    "canEdit" => [
      [ "state" => "draft", "hasPermission" => "canRead" ],
    ],
    "canCreate" => [
      [ "hasPermission" => [ "canEdit", "isCreateable" ] ],
    ],
    "canStateChange.from.draft.to.new" => [
      [ "hasPermission" => "canEdit" ],
    ],
  ],
  "newStateActions" => [
    "from.draft.to.new" => [ [ "copy" => true, "type" => "projekt-intern-genehmigung", "revision" => "v1", "redirect" => true ] ],
  ],
  "proposeNewState" => [
    "draft" => [ "new" ],
  ],
];

registerFormClass( "projekt-intern", $config );

