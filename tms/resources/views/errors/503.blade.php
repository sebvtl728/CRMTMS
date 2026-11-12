@extends('errors.layout', ['status' => 503])

@section('title', "Service indisponible")
@section('message', "Le service est momentanément indisponible (maintenance en cours). Réessayez dans quelques minutes.")
