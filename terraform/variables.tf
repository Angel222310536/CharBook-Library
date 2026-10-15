variable "location" {
  type        = string
  default     = "mexicocentral"
  description = "Región de Azure para desplegar los recursos"
}

variable "prefix" {
  type        = string
  default     = "charbook"
  description = "Prefijo para nombrar los recursos"
}

variable "environment" {
  type        = string
  default     = "prod"
  description = "Ambiente de despliegue"
}