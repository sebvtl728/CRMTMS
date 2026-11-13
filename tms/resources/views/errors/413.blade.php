@extends('errors.layout', ['status' => 413])

@section('title', "Fichier trop volumineux")
@section('message', "Le fichier envoyé dépasse la taille autorisée. Réduisez-le puis réessayez.")
