@extends('errors.layout', ['status' => 403])

@section('title', "Accès refusé")
@section('message', "Vous n'avez pas les droits nécessaires pour accéder à cette page.")
